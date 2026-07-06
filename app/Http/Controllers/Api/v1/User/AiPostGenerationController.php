<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use OpenAI\Laravel\Facades\OpenAI;
use App\Models\Chapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use OpenAI\Exceptions\ErrorException;
use CURLFile;
use Auth;
use Intervention\Image\Laravel\Facades\Image;
use App\Services\CreditService;
use App\Services\ImageGenerationPricingService;
use App\Services\PostAutoPublishService;


class AiPostGenerationController extends ResponseController
{
    public function __construct(
        protected CreditService $credits,
        protected ImageGenerationPricingService $pricing,
        protected PostAutoPublishService $autoPublish,
    ) {}

    public function generateContent(Request $request)
    {
        $isDraftOnly = $request->boolean('draft_only');

        // post_type/slides/design only matter once we're actually generating
        // images - the draft step only needs the chapter, model, and prompt.
        $rules = [
            'chapter' => 'required|exists:chapters,id',
            'model'   => 'required|string', // e.g., 'gpt-4-turbo', 'claude-3-haiku-20240307'
            'prompt'  => 'nullable|string',
            'text_format' => 'required|string|in:paragraph,bullet_points',

            // Draft/approve flow: draft_only returns text for review without
            // charging credits or generating images. feedback+previous_text
            // are used when the user asked for a revision. approved_text
            // skips text generation entirely and generates images straight
            // from the text the user already reviewed and accepted.
            'draft_only' => 'nullable|boolean',
            'feedback' => 'nullable|string',
            'previous_text' => 'nullable|array',
            'approved_text' => 'nullable|array',
            'approved_text.title' => 'required_with:approved_text|string',
            'approved_text.caption' => 'required_with:approved_text|string',
            'approved_text.summary' => 'nullable|string',
            'approved_text.image_text' => 'required_with:approved_text|string',
            'approved_text.hashtags' => 'nullable|string',

            // Which engine actually renders the image - independent from
            // which model wrote the text. Defaults to the text model's
            // engine if omitted (backward compatible).
            'image_model' => 'nullable|in:openai,gemini',

            // Publish-without-review: images generate synchronously inside
            // this request, so unlike HeyGen/Grok no background poller is
            // needed - the post is created, media attached, and published or
            // scheduled before the response returns, via the same
            // PostAutoPublishService flow. caption here is the social post
            // caption the user approved (approved_text.image_text is what
            // goes ON the image - different thing).
            'publish_action' => 'nullable|in:review,publish_now,schedule',
            'caption' => 'required_if:publish_action,publish_now,schedule|nullable|string',
            'hashtags' => 'nullable|string',
            'scheduled_at' => 'required_if:publish_action,schedule|nullable|date|after:now',
            'platforms' => 'required_if:publish_action,publish_now,schedule|nullable|array',
            'platforms.*' => 'in:instagram,instagram_story,tiktok',
            'content_disclose' => 'nullable',
            'brand_organic' => 'nullable',
            'branded_content' => 'nullable',
            'allow_comment' => 'nullable',
            'allow_duet' => 'nullable',
            'allow_stitch' => 'nullable',
            'privacy_level' => 'nullable',
        ];

        if (! $isDraftOnly) {
            $rules['post_type'] = 'required|in:carousel,single';
            $rules['slides'] = 'required|numeric|min:1|max:4';
            $rules['design'] = 'required|array';
            $rules['design.image_style'] = 'required|string';
            $rules['design.content_angle'] = 'required|string';
            $rules['design.human_presence'] = 'required|string';
            $rules['design.visual_mood'] = 'required|string';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->first());
        }

        $chapter = Chapter::join('book_chapters', 'chapters.chapter', '=', 'book_chapters.chapter')
            ->where('chapters.id', $request->chapter)
            ->select('chapters.id', 'chapters.chapter','chapters.content', 'book_chapters.chapter_title as chapter_title')
            ->first();
        if (!$chapter) {
            $chapter = Chapter::where('id', $request->chapter)->first();
        }
        $modelChoice = $request->model;
        $postType    = $request->post_type;
        $slidesCount = (int) $request->slides;
        $design      = $request->design ?? null;
        $textFormat  = $request->text_format;

        $finalPrompt = $this->buildTextGenerationPrompt($request->prompt, $request->feedback, $request->previous_text);

        // DRAFT STEP: generate/regenerate the caption text only, for the user
        // to review and approve before anything is charged or images made.
        if ($request->boolean('draft_only')) {
            try {
                $structuredData = $this->draftStructuredText($modelChoice, $finalPrompt, $chapter, $textFormat);

                return $this->sendResponse([
                    'title' => $structuredData['title'] ?? '',
                    'caption' => $structuredData['caption'] ?? '',
                    'hashtags' => $structuredData['hashtags'] ?? '',
                    'summary' => $structuredData['summary'] ?? '',
                    'image_text' => $structuredData['image_text'] ?? '',
                ], 'Draft generated successfully', 200);
            } catch (\Throwable $e) {
                return $this->sendError('Could not generate draft text', ['error' => $e->getMessage()], 500);
            }
        }

        // The image engine defaults to whatever the text model implies
        // (gpt/claude -> OpenAI images, gemini -> Gemini images), but the
        // user can pick a different engine explicitly once they've approved
        // the text, since it's now a separate step from text generation.
        // Cost is known exactly upfront since engine and slide count are
        // both known before any AI call happens; only which slides succeed
        // is uncertain.
        $imageEngine = $request->input('image_model')
            ?: ((str_contains($modelChoice, 'gpt') || str_contains($modelChoice, 'claude')) ? 'openai' : 'gemini');

        $user = Auth::user();
        $hold = $this->pricing->estimatedHoldCredits($imageEngine, $slidesCount);

        if (! $this->credits->hasSufficientBalance($user, $hold)) {
            return $this->sendError(
                "Not enough credits. This generation requires {$hold} credits, you have {$user->credits_balance}.",
                ['credits_required' => $hold, 'credits_balance' => $user->credits_balance],
                422
            );
        }

        if (! $this->credits->deduct($user, $hold, 'AI post generation (estimated hold)')) {
            return $this->sendError('Not enough credits.', [], 422);
        }

        try {
            if ($request->filled('approved_text')) {
                // Text was already drafted and approved by the user - skip
                // straight to image generation using it, no further text call.
                $response = $this->buildContentResponse($request->input('approved_text'), $modelChoice, $imageEngine, $chapter, $postType, $slidesCount, $design, $textFormat);
            } elseif (str_contains($modelChoice, 'gpt')) {
                $response = $this->generateWithOpenAI('gpt-5.4', $finalPrompt,$chapter,$postType,$slidesCount,$design,$textFormat);
            } elseif (str_contains($modelChoice, 'claude')) {
                $response = $this->generateWithClaude('claude-sonnet-5', $finalPrompt,$chapter,$postType,$slidesCount,$design,$textFormat);
            }else{
                $response = $this->generateWithGemini($modelChoice, $finalPrompt,$chapter,$postType,$slidesCount,$design,$textFormat);
            }
        } catch (\Throwable $e) {
            $this->credits->refund($user, $hold, 'Refund: AI post generation failed');
            return $this->sendError('Error generating content', ['error' => $e->getMessage()], 500);
        }

        $this->reconcileImageGenerationCost($user, $response, $hold, $imageEngine, $slidesCount);

        return $this->applyPublishAction($user, $request, $response);
    }

    /**
     * Publish-without-review for images. Since generation completed inside
     * this request, the whole pending_render lifecycle collapses into one
     * synchronous pass: create the post, record platforms, attach whichever
     * slides succeeded, then publish or schedule - same services the
     * HeyGen/Grok pollers use, no cron involved. If nothing succeeded the
     * response is returned untouched with publish_action reset to 'review',
     * which drops the frontend back into the normal draft/review flow
     * (nothing was created, nothing to publish).
     */
    protected function applyPublishAction($user, Request $request, $response)
    {
        $publishAction = $request->input('publish_action', 'review');

        if ($publishAction === 'review') {
            return $response;
        }

        $payload = json_decode($response->getContent(), true);

        if (! ($payload['success'] ?? false)) {
            return $response;
        }

        // Same success test the Library auto-save uses: only an explicit
        // status === false counts as a failed slide.
        $images = collect($payload['data']['images'] ?? [])
            ->filter(fn ($img) => ($img['status'] ?? true) !== false && ! empty($img['image_url']))
            ->values();

        if ($images->isEmpty()) {
            $payload['data']['publish_action'] = 'review';
            return response()->json($payload, 200);
        }

        try {
            // Mirrors the Library auto-save-draft rules: a batch that ended
            // up with one usable image becomes a single post regardless of
            // what was requested.
            $mediaAssets = $images->count() === 1 ? 'single' : ($payload['data']['post_type'] ?? 'carousel');
            $urls = ($mediaAssets === 'single' ? $images->take(1) : $images)->pluck('image_url')->all();

            $post = $this->autoPublish->createPendingPost(
                $user,
                (int) $request->chapter,
                $request->caption,
                $payload['data']['model'] ?? 'ChatGPT',
                $mediaAssets,
                $request->hashtags
            );

            $this->autoPublish->preparePendingPublish($post, [
                'platforms' => $request->platforms,
                'scheduled_at' => $publishAction === 'schedule' ? $request->scheduled_at : null,
                'content_disclose' => $request->content_disclose,
                'brand_organic' => $request->brand_organic,
                'branded_content' => $request->branded_content,
                'allow_comment' => $request->allow_comment,
                'allow_duet' => $request->allow_duet,
                'allow_stitch' => $request->allow_stitch,
                'privacy_level' => $request->privacy_level,
            ]);

            $this->autoPublish->finalizeReadyPost($post, $urls);

            $payload['data']['post_id'] = $post->id;
            $payload['data']['publish_action'] = $publishAction;

            return response()->json($payload, 200);
        } catch (\Throwable $e) {
            // The images themselves succeeded and were paid for - never fail
            // the whole request over the publish step. Fall back to the
            // review flow so the frontend auto-saves them as a draft.
            Log::error('Auto-publish after image generation failed', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            $payload['data']['publish_action'] = 'review';
            return response()->json($payload, 200);
        }
    }

    /**
     * Builds the prompt sent to the text model. When the user disapproved a
     * draft and asked for changes, the previous attempt and their requested
     * changes are included so the revision actually addresses the feedback
     * rather than starting over blind.
     */
    private function buildTextGenerationPrompt(?string $userPrompt, ?string $feedback, ?array $previousText): string
    {
        $task = trim((string) $userPrompt) !== ''
            ? $userPrompt
            : 'Write an engaging, accurate post covering the most interesting and important ideas from this chapter.';
        $prompt = "Task: {$task}";

        if ($feedback) {
            $previousCaption = $previousText['caption'] ?? '';
            $previousImageText = $previousText['image_text'] ?? '';
            $prompt .= "\n\nYou previously wrote this caption:\n{$previousCaption}"
                . "\n\nAnd this text for the image itself:\n{$previousImageText}"
                . "\n\nThe user requested these changes: {$feedback}"
                . "\n\nRevise the draft to address the requested changes, keeping the same required JSON structure.";
        }

        return $prompt;
    }

    /**
     * Dispatches to the right model's text-only fetch for the draft step -
     * same routing rule as generateContent()'s final-generation branch.
     */
    private function draftStructuredText(string $modelChoice, string $prompt, $chapter, string $textFormat): array
    {
        if (str_contains($modelChoice, 'gpt')) {
            return $this->fetchOpenAIText('gpt-5.4', $prompt, $chapter, $textFormat);
        } elseif (str_contains($modelChoice, 'claude')) {
            return $this->fetchClaudeText('claude-sonnet-5', $prompt, $chapter, $textFormat);
        }

        return $this->fetchGeminiText($modelChoice, $prompt, $chapter, $textFormat);
    }

    /**
     * Shared by the final-generation path (with a freshly-approved-text
     * shortcut) so both draft and non-draft flows produce an identical
     * response shape for the frontend.
     */
    private function buildContentResponse(array $structuredData, string $modelChoice, string $imageEngine, $chapter, $postType, $slidesCount, $design, $textFormat)
    {
        // getImages()/generateGeminiImage() route purely on this value being
        // exactly 'gemini' vs anything else - see getImages() below.
        $imageModelParam = $imageEngine === 'gemini' ? 'gemini' : 'gpt-5.4-mini';

        $modelLabel = str_contains($modelChoice, 'gpt')
            ? 'ChatGPT'
            : (str_contains($modelChoice, 'claude') ? 'Claude' : 'Gemini');

        $images = $this->getImages($chapter, $postType, $slidesCount, $design, $imageModelParam, $textFormat, $structuredData['image_text'] ?? '');

        return $this->sendResponse([
            'caption' => ($structuredData['title'] ?? '') . PHP_EOL . ($structuredData['caption'] ?? ''),
            'hashtags' => $structuredData['hashtags'] ?? '',
            'summary' => $structuredData['summary'] ?? '',
            'image_text' => $structuredData['image_text'] ?? '',
            'title' => $structuredData['title'] ?? '',
            'model' => $modelLabel,
            'post_type' => $postType,
            'slides' => $slidesCount,
            'images' => $images,
            'chapter' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $chapter->chapter),
            'chapter_title' => $chapter->chapter_title,
            'chapter_id' => $chapter->id,
            'ai_prompt' => $structuredData['caption'] ?? '',
        ], 'Content generated successfully', 200);
    }

    /**
     * Refund the difference between the upfront hold and what was actually
     * delivered: a full refund if the text call itself failed (no images
     * were ever attempted), or a partial refund for any slide that failed
     * to generate. Charges only for images that actually succeeded.
     */
    protected function reconcileImageGenerationCost($user, $response, int $held, string $imageEngine, int $slidesCount): void
    {
        $payload = json_decode($response->getContent(), true);

        if (! ($payload['success'] ?? false)) {
            $this->credits->refund($user, $held, 'Refund: AI post generation failed');
            return;
        }

        $images = $payload['data']['images'] ?? [];
        $successfulImages = collect($images)->where('status', true)->count();

        $actualCost = $this->pricing->textGenerationCostCredits()
            + ($this->pricing->imageCostCredits($imageEngine) * $successfulImages);

        $refund = $held - $actualCost;

        if ($refund > 0) {
            $this->credits->refund($user, $refund, "Refund: {$successfulImages}/{$slidesCount} images generated successfully");
        } elseif ($refund < 0) {
            Log::warning('AI post generation actual cost exceeded the held credits', [
                'user_id' => $user->id,
                'held' => $held,
                'actual_cost' => $actualCost,
                'successful_images' => $successfulImages,
            ]);
        }
    }

    /**
     * Text-only OpenAI call, used by both the draft step and the final
     * generation step (generateWithOpenAI below) so behavior stays identical
     * regardless of which path is taken.
     */
    private function fetchOpenAIText($model, $prompt, $chapter, $textFormat): array
    {
        $systemInstruction = $this->getSystemInstruction($chapter, $textFormat);

        $result = OpenAI::chat()->create([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemInstruction],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        $rawContent = $result->choices[0]->message->content;
        if (!$rawContent) {
            throw new \Exception("AI returned empty content.");
        }

        $structuredData = json_decode($rawContent, true);
        if (is_null($structuredData)) {
            throw new \Exception("Invalid JSON format received from AI.");
        }

        return $structuredData;
    }

    private function fetchClaudeText($model, $prompt, $chapter, $textFormat): array
    {
        $systemInstruction = $this->getSystemInstruction($chapter, $textFormat);

        $response = Http::withHeaders([
            'x-api-key' => Config::get('constant.claud_keys.key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => 2048,
            'system' => $systemInstruction,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
        ]);

        if ($response->failed()) {
            $errorData = $response->json();
            $errorMessage = $errorData['error']['message'] ?? 'Unknown Claude API Error';
            throw new \Exception("Claude API Error: " . $errorMessage);
        }

        $resData = $response->json();

        if (!isset($resData['content'][0]['text'])) {
            throw new \Exception("Unexpected API response structure.");
        }

        $rawContent = $resData['content'][0]['text'];
        $cleanContent = preg_replace('/^```json\s*|\s*```$/', '', trim($rawContent));
        $structuredData = json_decode($cleanContent, true);

        if (is_null($structuredData)) {
            throw new \Exception("Invalid JSON format received from AI.");
        }

        return $structuredData;
    }

    private function fetchGeminiText($model, $prompt, $chapter, $textFormat): array
    {
        $systemInstruction = $this->getSystemInstruction($chapter, $textFormat);
        $apiKey = Config::get('constant.gemini_keys.key');

        $response = Http::withHeaders([
            'x-goog-api-key' => $apiKey,
            'Content-Type'  => 'application/json',
        ])->timeout(120)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro-latest:generateContent',
            [
                'system_instruction' => [
                    'parts' => [
                        ['text' => $systemInstruction]
                    ]
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
            ]
        );

        if ($response->failed()) {
            $errorData = $response->json();
            $errorMessage = $errorData['error']['message'] ?? 'Unknown Gemini API Error';
            throw new \Exception("Gemini API Error: " . $errorMessage);
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (!$text) {
            throw new \Exception('Empty response from Gemini.');
        }

        $text = trim($text);
        $text = preg_replace('/^```json\s*/', '', $text);
        $text = preg_replace('/^```\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text, "\" \n\r\t");

        $data = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON returned from Gemini: ' . json_last_error_msg());
        }

        if (is_null($data) || !is_array($data)) {
            throw new \Exception("Invalid JSON format received from Gemini. Try again");
        }

        return $data;
    }

    private function generateWithOpenAI($model, $prompt,$chapter,$postType,$slidesCount,$design,$textFormat)
    {
        try {
            $structuredData = $this->fetchOpenAIText($model, $prompt, $chapter, $textFormat);
            $images = $this->getImages($chapter, $postType, $slidesCount, $design, $model,$textFormat, $structuredData['image_text'] ?? '');

            return $this->sendResponse([
                'caption' => $structuredData['title'] . PHP_EOL . $structuredData['caption'],
                'hashtags' => $structuredData['hashtags'],
                'summary' => $structuredData['summary'],
                'image_text' => $structuredData['image_text'] ?? '',
                'title' => $structuredData['title'],
                'model' => 'ChatGPT',
                'post_type' => $postType,
                'slides' => $slidesCount,
                'images' => $images,
                'chapter' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $chapter->chapter),
                'chapter_title' => $chapter->chapter_title,
                'chapter_id' => $chapter->id,
                'ai_prompt' => $prompt,
            ], 'Content generated successfully', 200);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'rate limit')) {
                sleep(2); // wait before retry
            }

            return $this->sendError('Error generating content chatgpt due to ' . $e->getMessage(), ['error' => $e->getMessage()], 500);
        }
    }


    private function generateWithClaude($model, $prompt, $chapter, $postType, $slidesCount, $design,$textFormat)
    {
        try {
            $structuredData = $this->fetchClaudeText($model, $prompt, $chapter, $textFormat);
            $images = $this->getImages($chapter, $postType, $slidesCount, $design, $model,$textFormat, $structuredData['image_text'] ?? '');

            return $this->sendResponse([
                'caption' => $structuredData['title'] . PHP_EOL . $structuredData['caption'],
                'hashtags' => $structuredData['hashtags'] ?? '',
                'summary' => $structuredData['summary'] ?? '',
                'image_text' => $structuredData['image_text'] ?? '',
                'title' => $structuredData['title'] ?? '',
                'model' => 'Claude',
                'post_type' => $postType,
                'slides' => $slidesCount,
                'images' => $images,
                'chapter' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $chapter->chapter),
                'chapter_title' => $chapter->chapter_title,
                'chapter_id' => $chapter->id,
                'ai_prompt' => $prompt,
            ], 'Content generated successfully', 200);
        } catch (\Throwable $e) {
            return $this->sendError('Error generating content claude', ['error' => $e->getMessage()], 500);
        }
    }

    Private function generateWithGemini($model, $prompt, $chapter, $postType, $slidesCount, $design,$textFormat)
    {
        try {
            $data = $this->fetchGeminiText($model, $prompt, $chapter, $textFormat);
            $images = $this->getImages($chapter, $postType, $slidesCount, $design, $model,$textFormat,$data['image_text'] ?? '');

            return $this->sendResponse([
                'caption' => $data['title'] . PHP_EOL . $data['caption'],
                'hashtags' => $data['hashtags'],
                'summary' => $data['summary'],
                'image_text' => $data['image_text'] ?? '',
                'title' => $data['title'],
                'model' => 'Gemini',
                'post_type' => $postType,
                'slides' => $slidesCount,
                'images' => $images,
                'chapter' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $chapter->chapter),
                'chapter_title' => $chapter->chapter_title,
                'chapter_id' => $chapter->id,
                'ai_prompt' => $prompt,
            ], 'Content generated successfully', 200);
        } catch (\Throwable $e) {
            \Log::error('Gemini Exception: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return $this->sendError('Error generating content gemini', ['error' => $e->getMessage()], 500);
        }
    }


    private function extract(string $text, string $key): string
    {
        preg_match("/{$key}:\s*(.*)/i", $text, $matches);
        return $matches[1] ?? '';
    }

    // private function generateAIImage($prompt, $model = 'dall-e-3')
    // {
    //     try {
    //         Log::info('Call function count');
    //         $response = OpenAI::images()->create([
    //             'model' => $model,
    //             'prompt' => $prompt,
    //             'size' => '1024x1792', // portrait
    //             'quality' => 'standard',
    //         ], [
    //             'timeout' => 120, //  IMPORTANT
    //         ]);

    //         Log::info('image function response', [$response]);
    //         return $response->data[0]->url; // Temporary URL from OpenAI

    //     } catch (\Exception $e) {
    //         return $this->sendError('Error generating image', ['error' => $e->getMessage()], 500);
    //     }

    // }

    private function generateAIImage($prompt,$imagePath)
    {
        
        $apiKey = Config::get('constant.open_ai_keys.key');
      
        try{

            $ch = curl_init("https://api.openai.com/v1/images/edits");

            // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // curl_setopt($ch, CURLOPT_POST, true);
            // curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            // curl_setopt($ch, CURLOPT_HTTPHEADER, [
            //     "Authorization: Bearer {$apiKey}"
            // ]);

            // curl_setopt($ch, CURLOPT_POSTFIELDS, [
            //     "model" => "gpt-image-1",
            //     "prompt" => $prompt,
            //     "image" => new CURLFile($imagePath, "image/png"),
            //     "size" => "1024x1536", //"1024x1280",
            //     // "response_format" => "b64_json"
            // ]);
    
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 280,              // total timeout
                CURLOPT_CONNECTTIMEOUT => 30,        // connection timeout
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$apiKey}"
                ],
                CURLOPT_POSTFIELDS => [
                    "model" => "gpt-image-2",//"gpt-image-1.5",
                    "prompt" => $prompt,
                    "image" => new CURLFile($imagePath, mime_content_type($imagePath)),
                    "size" => "1024x1536", 
                    "quality" => "low",
                ]
            ]);
            
            $response = curl_exec($ch);
            if ($response === false) {
                \Log::error('OpenAI image generation: curl_exec failed', [
                    'curl_errno' => curl_errno($ch),
                    'curl_error' => curl_error($ch),
                ]);
                curl_close($ch);

                return [
                    'success' => false,
                    'image_url' => '',
                    'error' => 'Currently this model server is under high load. Try another AI model or upload manually.'
                ];
            }
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $result = json_decode($response, true);

            if ($httpCode != 200) {

                $errorMessage = $result['error']['message']
                    ?? 'OpenAI image generation failed';

                \Log::error('OpenAI image generation: non-200 response', [
                    'http_code' => $httpCode,
                    'error_message' => $errorMessage,
                    'error_type' => $result['error']['type'] ?? null,
                    'error_code' => $result['error']['code'] ?? null,
                ]);

                return [
                    'success' => false,
                    'image_url' => '',
                    'error' => 'Currently this model server is under high load. Try another AI model or upload manually.'
                ];
            }
            $base64 = $result['data'][0]['b64_json'] ?? null;
            if (!$base64) {
                \Log::error('OpenAI image generation: response missing b64_json', [
                    'http_code' => $httpCode,
                    'response_keys' => is_array($result) ? array_keys($result) : null,
                ]);

                return [
                    'success' => false,
                    'image_url' => '',
                    'error' => 'Currently this model server is under high load. Try another AI model or upload manually.'
                ];
            }
            // Create data URL
            // $imageUrl = 'data:image/png;base64,' . $base64;
            $imageData = base64_decode($base64);
            
            // Generate a unique filename and save to your public disk
            $fileName = 'posts/temp/ai_' . uniqid() . '.jpg';
            Storage::disk('public')->put($fileName, $imageData);
            $imageUrl = Config::get('constant.media_base_url').config('constant.media_base_path').$fileName;
            return [
                'success' => true,
                'image_url' => $imageUrl,
                'error' => null
            ];
        } catch (\Exception $e) {
            \Log::error('OpenAI image generation: exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'success' => false,
                'image_url' => '',
                'error' => 'Currently this model server is under high load. Try another AI model or upload manually.'
            ];
        }

    }

    private function buildSlideImagePrompt($chapter, $design,$textFormat, $imageText=null)
    {
        $imagePath = Storage::disk('public')->path('assets/cover-image.png');
        $affiliate_id = Auth::user()->affiliate_id;
        $prompt = "Create a clean, professional medical infographic image in a 1:1 square format optimized for Instagram for a medical educational post. 
            TITLE: 'The Carbonated Body' (Large, elegant serif font at the top).
            SUBTITLE: 'Chapter {$chapter->chapter}: {$chapter->chapter_title}' (Positioned below the title).

            VISUAL CENTERPIECE: 
            Visual style: {$design['image_style']}
            Mood: {$design['visual_mood']}
            Audience tone: {$design['human_presence']}
            Angle: {$design['content_angle']}
            
            CONTENT SECTION:
            TEXT ACCURACY IS HIGHEST PRIORITY.

            On a clean semi-transparent overlay or clear negative space, render this EXACT text, verbatim and unchanged - the user has already reviewed and approved this precise wording, do not summarize, paraphrase, shorten, or reword it in any way:
            {$imageText}

            STRICT CONTENT RULES:
            Render the approved text exactly as given, character for character.
            Do not add, remove, or alter any words.
            Use simple common English words as already written.
            No long explanations.
            No filler text.
            No technical overload.
            No hashtags.
            No special symbols.
            No decorative characters.
            No broken words.
            No incomplete sentences.
            No meaningless text.
            No random letters.
            No gibberish text.
            No merged words.
            No distorted typography.
            
            TYPOGRAPHY RULES:
            Clean modern sans-serif typography.
            Large readable text only.
            Perfect spelling required.
            Even line spacing.
            Strong contrast against background.
            No overlapping elements.
            No warped or curved text.
            No ultra-thin fonts.
            
            LAYOUT RULES:
            Center-aligned composition.
            Maintain generous empty spacing around text.
            Keep all text strictly inside safe readable zones.
            Prioritize readability over adding more text.
            If space becomes limited, reduce text amount instead of reducing readability.

            THUMBNAIL ELEMENT:
            In the bottom-right corner, place the EXACT provided book cover image from {$imagePath} as a static thumbnail.
            Do NOT redesign, recolor, restyle, reinterpret, or regenerate the cover.
            Preserve the original text, colors, typography, proportions, and layout exactly as provided.
            The cover must be used as-is, unchanged, and scaled down only.

            AFFILIATE FOOTER:
            - At the very bottom inside the safe area, centered horizontally, 
            include this exact URL in small, clean, readable typography:'https://co2body.com/{$affiliate_id}'

            TECHNICAL SPECIFICATIONS:
           - All visible content must be placed strictly inside a centered inner safe area.
           - Image Size: SD (Standard Definition).
           - Composition: High-end medical journal aesthetic.
           - Layout: Top-heavy text, center visual, bottom-right thumbnail.
           - Ensure all text is legible and centered within the frame with safe-zone margins.
            
            IMPORTANT LAYOUT RULES:
            - Use a 4:5 vertical layout (1024x1536).
            - Keep all text within safe margins (at least 12% padding top and bottom).
            - Title must be fully visible at the top.
            - Book cover thumbnail must be fully visible at the bottom-right.
            - No cropping or edge-clipped text
            ";

         return ['prompt'=>$prompt,'imagepath'=>$imagePath];
    }

    private function getImages($chapter, $postType, $slidesCount, $design, $model,$textFormat,$imageText=null)
    {
        // dd($design);
        $images = [];
        if($model === 'gemini'){
            $imagePrompt = $this->buildGeminiImagePrompt($chapter,$design,$textFormat, $imageText);
        }else{
            $imagePrompt = $this->buildSlideImagePrompt($chapter,$design,$textFormat, $imageText);
        }
       
        set_time_limit(900);   
        
        
        if ($postType === 'carousel') {
            
            for ($i = 0; $i < $slidesCount; $i++) {
                
                 set_time_limit(180);   
                
                \Log::info('gemini call:--');
                if($model === 'gemini'){
                    $result = $this->generateGeminiImage($imagePrompt['prompt'], $imagePrompt['imagepath']);
                    $images[] = [
                        'slide' => $i + 1,
                        'image_url' => $result['image_url'],
                        'image_error' => $result['success'] ? null : $result['error'],
                        'status' => $result['success'],
                        'model_used' =>$result['model_used'],
                    ];

                    
                    if ($i < $slidesCount - 1) {
                        sleep(1); 
                    }
                }else{
                    
                    $result = $this->generateAIImage($imagePrompt['prompt'],$imagePrompt['imagepath']);
                    // \Log::info('result of open ai image',[$result]);
                    $images[] = [
                        'slide' => $i + 1,
                        'image_url' => $result['image_url'],
                        'image_error' => $result['success'] ? null : $result['error'],
                        'status' => $result['success'],
                    ];

                    if ($i < $slidesCount - 1) {
                        sleep(1); 
                    }
                }
                
            }

        } else {
            // Single post
            if($model === 'gemini'){
                $result = $this->generateGeminiImage($imagePrompt['prompt'], $imagePrompt['imagepath']);
                $images[] = [
                    'slide' => 1,
                    'image_url' => $result['image_url'],
                    'image_error' => $result['success'] ? null : $result['error'],
                    'status' => $result['success'],
                    'model_used' =>$result['model_used'],
                ];
            }else{
                
                $result = $this->generateAIImage($imagePrompt['prompt'],$imagePrompt['imagepath']);

                $images[] = [
                    'slide' => 1,
                    'image_url' => $result['image_url'],
                    'image_error' => $result['success'] ? null : $result['error'],
                    'status' => $result['success'],
                ];
            }
        }
        
        // \Log::info('final result of getimage',[$images]);

        return $images;
    }


    private function generateGeminiImage($prompt, $imagepath) 
    {
        set_time_limit(300);
        $apiKey = Config::get('constant.gemini_keys.key');
        $response = null;
        // Pro (Nano Banana Pro) first - it's the only Gemini image model
        // that renders on-image text reliably, which these posts depend on.
        // Flash models are fallbacks if Pro errors; pricing in
        // config/services.php (image_gemini_cost_cents) tracks Pro's cost.
        $models = [
            'gemini-3-pro-image',
            'gemini-3.1-flash-image',
            'gemini-2.5-flash-image',
        ];
        foreach ($models as $model) {
            try{
                // 2. Execute the Request
                        //model : gemini-2.5-flash-image
                $response = Http::timeout(300)
                     ->connectTimeout(60)
                    ->withHeaders([
                        'x-goog-api-key' => $apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post(
                        "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                        [
                            "contents" => [
                                [
                                    "parts" => [
                                        [
                                            "inline_data" => [
                                                "mime_type" => "image/png",
                                                "data" => $imagepath,
                                            ]
                                        ],
                                        ["text" => $prompt] 
                                    ]
                                
                                ]
                            ],
                            "generationConfig" => [
                                "responseModalities" => ["IMAGE"],
                                "imageConfig" => [
                                    "aspectRatio" => "4:5",
                                    "imageSize" => "1K",
                                ],
                                // "temperature" => 1.0
                            ]
                        ]
                    );
                // dd($response);
               

                $status = $response->status();
                \Log::info('status gemini:-',['status'=>$status]);

                if ($response->successful()) {
                    // $data = $response->json();

                    // // 3. Extract Base64 and save as URL
                    
                    $data = json_decode($response->body(), true);
                    
                    // if (!$data) {
                    //     \Log::error('Invalid JSON from Gemini Image API', [
                    //         'body' => $response->body()
                    //     ]);
                    //     // return $this->sendError('Invalid JSON from Gemini', [], 500);
                    //     return [
                    //         'success' => false,
                    //         'image_url' => '',
                    //         'error' => 'Currently this model server is under high load. Try another AI model or upload manually.'
                    //     ];
                    // }

                    // $base64 = data_get($data, 'candidates.0.content.parts.0.inline_data.data');
                    $base64 = data_get($data, 'candidates.0.content.parts.0.inlineData.data');
                    // if (!$base64) {
                    //     \Log::error('No image data returned', $data);
                    //     // return $this->sendError('No image generated', $data, 500);
                    //     return [
                    //         'success' => false,
                    //         'image_url' => '',
                    //         'error' => 'Currently this model server is under high load. Try another AI model or upload manually.'
                    //     ];
                    // }
                    // $mimeType = data_get($data, 'candidates.0.content.parts.0.inline_data.mime_type', 'image/png');
                    
                    //$mimeType = data_get($data, 'candidates.0.content.parts.0.inlineData.mimeType', 'image/png');
                   
                    // This creates a "URL" that contains the image itself
                   // $dataUrl = "data:{$mimeType};base64,{$base64}";
                    // return $dataUrl;
                    
                    $imageData = base64_decode($base64);

                    // Generate a unique filename and save to your public disk
                    $fileName = 'posts/temp/gai_' . uniqid() . '.jpg';
                    Storage::disk('public')->put($fileName, $imageData);
                    $dataUrl = Config::get('constant.media_base_url').config('constant.media_base_path').$fileName;
                    return [
                        'success' => true,
                        'image_url' => $dataUrl,
                        'error' => null,
                        'model_used' => $model
                    ];
                    
                }
                
                $data = $response->json() ?: [];
                $errorMessage = $data['error']['message'] ?? 'Gemini API error';
                \Log::error('Gemini image API error', ['status' => $status, 'model' => $model, 'error' => $errorMessage]);

                // Free-tier API keys have quota limit 0 for image models -
                // image generation simply isn't included in Google's free
                // tier, so retrying or switching models can never help.
                // Surface the real problem instead of "server busy".
                if ($status === 429 && str_contains($errorMessage, 'free_tier')) {
                    return [
                        'success' => false,
                        'image_url' => '',
                        'error' => 'Gemini image generation needs a paid Google AI plan - the current API key is free-tier (image quota is 0). Use the OpenAI engine, or upgrade the key at aistudio.google.com.',
                        'model_used' => $model
                    ];
                }

                $retryDelay = 2;
                //RETRYABLE SERVER ERRORS → try next model
                if (in_array($status, [429, 500, 502, 503, 504])) {

                    sleep($retryDelay);
                    $retryDelay *= 2; // exponential backoff
                    continue;
                }
                //  FATAL ERRORS → stop immediately
                return [
                    'success' => false,
                    'image_url' => '',
                    'error' => "Gemini image error: {$errorMessage}",
                    'model_used' => $model
                ];
            } catch (\Exception $e) {
                Log::error('Gemini image failed', [
                    'status' => $response?->status(),
                    'body' => $response?->body(),
                    'error' => $e->getMessage()
                ]);
                // return $this->sendError('Error generating gemini image', ['error' => $e->getMessage()], 500);
                return [
                    'success' => false,
                    'image_url' => '',
                    'error' => 'Currently this model server is down. Try another AI model or upload manually.',
                    'model_used' => null
                ];
            }
        }
        // All models failed (retryable errors exhausted)
        return [
            'success' => false,
            'image_url' => '',
            'error' => 'AI image servers are currently overloaded. Please try again later or upload manually.',
            'model_used' => null
        ];
        
    }
    
    private function buildGeminiImagePrompt($chapter,$design,$textFormat, $imageText=null)
    {
        
        $image_storage_path = Storage::disk('public')->path('assets/cover-image.png');
        //$imagepath =  base64_encode(file_get_contents($image_storage_path));
       
        $img = imagecreatefrompng($image_storage_path);
        $resized = imagescale($img, 1024); // Reduce size for the API payload
        ob_start();
        imagepng($resized);
        $imagepath = base64_encode(ob_get_clean());
        imagedestroy($img);
        imagedestroy($resized);
       
        $affiliate_id = Auth::user()->affiliate_id;
        
        //    dd($apiKey);

        // $prompt = "Create a clean, professional medical infographic image optimized for Instagram portrait posts.
        
        //         Target size & format:
        //         - Portrait layout, 1080 × 1350 px (4:5 ratio) for maximum feed coverage.
        //         - All important content must be placed strictly inside a centered inner safe area to avoid cropping.

        //         Content to include inside the safe area:
        //         - A semi-transparent illustration highlighting {$chapter->chapter_title}.
        //         - Title at the top: 'The Carbonated Body'
        //         - SUBTITLE: '{$chapter->chapter}: {$chapter->chapter_title}' (Positioned below the title)
        //         - On a clean, semi-transparent overlay or clear negative space, include {$textFormat} summarizing key concepts from a {$design['content_angle']} about {$chapter->chapter_title}
        //         - Provide plenty of breathing space between the content and the edges.

        //         THUMBNAIL ELEMENT:
        //         - In the bottom-left corner, place the EXACT cover image provided in payload as a static thumbnail.
        //         - Do NOT redesign, recolor, restyle, reinterpret, or regenerate the cover image.
        //         - Preserve the original text, colors, typography, proportions, and layout exactly as provided.
        //         - Scale down the cover only as needed.

        //         AFFILIATE FOOTER:
        //         - At the very bottom inside the safe area, centered horizontally, 
        //         include this exact URL in small, clean, readable typography:'https://co2body.com/{$affiliate_id}'

        //         VISUAL CENTERPIECE: 
        //         - Use a 4:5 vertical layout (1080 × 1350 px).
        //         Visual style: {$design['image_style']}
        //         Mood: {$design['visual_mood']}
        //         Audience tone: {$design['human_presence']}
        //         Angle: {$design['content_angle']}

        //         Ensure balanced composition and high clarity suitable for Instagram viewing without losing any important content.";
                
        // $prompt = "Create a clean, professional medical infographic image optimized for Instagram portrait posts.

        //     STRICT TEXT RULES:
        //     All text must be written only in correct English.
        //     No random symbols, characters, or distorted letters.
        //     No misspellings.
        //     Use clean professional typography.
        //     Text must be perfectly readable and evenly spaced.
            
        //     CANVAS:
        //     Portrait layout 1080 × 1350 px (4:5).
        //     Maintain a safe margin on all sides.
        //     No content may touch edges.
            
        //     LAYOUT GRID (MANDATORY):
        //     Divide layout into zones:
            
        //     ZONE 1 — HEADER (Top 20%)
        //     Title: 'The Carbonated Body'
            
        //     ZONE 2 — SUBTITLE (Below header)
        //     '{$chapter->chapter}: {$chapter->chapter_title}'
            
        //     ZONE 3 — VISUAL ILLUSTRATION (Upper middle)
        //     Semi-transparent illustration highlighting {$chapter->chapter_title}.
        //     Illustration must contain NO text.
            
        //     ZONE 4 — TEXT PANEL (Center area)
        //     {$textFormat} summarizing key concepts from a {$design['content_angle']} about {$chapter->chapter_title}
            
        //     Formatting rules:
        //     bullet points or short lines
        //     evenly spaced
        //     no overlap
        //     centered composition
            
        //     ZONE 5 — RESERVED THUMBNAIL AREA (BOTTOM LEFT CORNER ONLY)
        //     STRICT POSITIONING RULES:
        //     Reserve a blank empty rectangle in the bottom-left corner.
        //     This space must contain NO text, NO illustrations, NO graphics.
        //     Place the provided cover image ONLY inside this reserved rectangle.
        //     The thumbnail must NEVER overlap any text or visual elements.
        //     Scale the thumbnail proportionally so it fits entirely inside its reserved corner space.
        //     Maintain padding around it.
            
        //     ZONE 6 — FOOTER (Bottom center)
        //     Centered URL:
        //     https://co2body.com/{$affiliate_id}
            
        //     Footer rules:
        //     Must not overlap thumbnail
        //     Must remain horizontally centered
        //     Must stay above bottom margin
        //     Small but clearly readable font
            
        //     VISUAL STYLE:
        //     Style: {$design['image_style']}
        //     Mood: {$design['visual_mood']}
        //     Tone: {$design['human_presence']}
        //     Angle: {$design['content_angle']}
            
        //     FINAL COMPOSITION RULES:
        //     Balanced layout
        //     Clean infographic design
        //     Clear spacing between sections
        //     No element overlaps another
        //     No crowding
        //     No clutter
        //     Maintain visual hierarchy
        //     Ensure every element stays inside its assigned zone
            
        //     If any element overlaps, regenerate until layout is clean and properly spaced.";
        
        // $prompt = "Create a clean, professional medical infographic optimized for Instagram (4:5).

        //     *** CRITICAL INSTRUCTION FOR TEXT RENDERING ***
        //     You are an infographic engine. DO NOT render the names of the zones (e.g., do not print 'ZONE 1', 'HEADER', 'CANVAS', etc.) on the image. Only render the text found inside the <PrintText> tags or the generated bullet points.
            
        //     <STRICT_TEXT_RULES>
        //         TEXT ACCURACY IS HIGHEST PRIORITY.
    
        //         - Language: Correct English only.
        //         - Every visible word must be correctly spelled.
        //         - No random symbols.
        //         - No distorted characters.
        //         - No broken letters.
        //         - No merged words.
        //         - No gibberish text.
        //         - No incomplete words.
        //         - No meaningless words.
        //         - No fake medical terms.
        //         - No repeated letters accidentally.
        //         - No decorative text.
        //         - Render ONLY intentional readable content.
                
        //         TYPOGRAPHY RULES:
        //         - Use clean modern sans-serif typography.
        //         - Perfect readability is mandatory.
        //         - Maintain even spacing between letters and lines.
        //         - Use large readable text only.
        //         - Avoid ultra-thin fonts.
        //         - Avoid artistic or stylized fonts.
        //         - Avoid warped or curved text.
        //         - Avoid overlapping text.
                
        //         FAILSAFE:
        //         If readability becomes difficult, reduce text amount instead of generating corrupted text.
        //     </STRICT_TEXT_RULES>
            
        //     <CANVAS_LAYOUT>
        //     - Dimensions: 1080 × 1350 px.
        //     - Margins: Maintain a safe internal margin; no elements should touch the extreme edges.
        //     </CANVAS_LAYOUT>
            
        //     <PLACEMENT_GRID>
        //         <SECTION_TOP_20>
        //             Position as Header:
        //             <PrintText>'The Carbonated Body'</PrintText>
                    
        //             Position as Subtitle:
        //             <PrintText>'{$chapter->chapter}: {$chapter->chapter_title}'</PrintText>
        //         </SECTION_TOP_20>
            
        //         <SECTION_VISUAL_MIDDLE_UPPER>
        //             Illustration Concept: A semi-transparent, hyper-realistic graphic highlighting '{$chapter->chapter_title}'.
        //             Restriction: This illustration area must contain ZERO text characters.
        //         </SECTION_VISUAL_MIDDLE_UPPER>
            
        //         <SECTION_CENTER_BODY>
        //             Content Task:
        //             Read the provided chapter summary carefully and create {$textFormat} as a SHORT Instagram-friendly educational micro-summary regarding '{$chapter->chapter_title}'.
                    
        //             STRICT LENGTH RULE:
        //             - Generate ONLY 35-50 words total.
        //             - Never exceed 50 words.
        //             - Use only 3-5 short lines.
        //             - Prioritize minimal Instagram-style text density.
        //             - If needed, shorten aggressively before rendering.
                    
        //             STRICT CONTENT RULES:
        //             - STRICTLY use the provided summary only.
        //             - Extract only the most important ideas.
        //             - Rewrite into short simple educational sentences.
        //             - Use only simple common English words.
        //             - Every sentence must be complete and meaningful.
        //             - No long explanations.
        //             - No filler text.
        //             - No technical overload.
        //             - No special symbols.
        //             - No hashtags.
        //             - No broken words.
        //             - No cut-off sentences.
        //             - No meaningless text.
        //             - Keep the content visually clean and highly readable.
                    
        //             SOURCE SUMMARY:
        //             {$summary}
                
        //             Formatting:
        //             - Center-aligned composition.
        //             - Large readable typography.
        //             - Balanced line spacing.
        //             - Clean visual hierarchy.
        //             - Keep enough empty space around text.
        //             - Ensure text occupies only the center body region without clutter.
        //         </SECTION_CENTER_BODY>
            
        //         <SECTION_BOTTOM_LEFT_THUMBNAIL>
        //             STRICT POSITIONING:
        //             Reserve a clean, empty rectangular space in the BOTTOM-LEFT CORNER ONLY.
                    
        //             THUMBNAIL RULES:
        //             Use the provided book cover image EXACTLY AS PROVIDED.
        //             DO NOT redesign, restyle, recreate, repaint, modify, enhance, crop, or reinterpret the cover image in any way.
        //             Maintain the original colors, typography, layout, proportions, and artwork.
                    
        //             PLACEMENT:
        //             Place the original cover image only inside the reserved thumbnail area.
        //             Scale it proportionally to fit completely within the space.
        //             Maintain proper padding on all sides.
        //             Ensure the thumbnail does not overlap or touch any text, graphics, or footer elements.
        //         </SECTION_BOTTOM_LEFT_THUMBNAIL>
            
        //         <SECTION_FOOTER_BOTTOM_CENTER>
        //             URL to print: https://co2body.com/{$affiliate_id}
        //             Style: Small, centered, readable font. Must remain horizontally centered and not overlap the thumbnail.
        //         </SECTION_FOOTER_BOTTOM_CENTER>
        //     </PLACEMENT_GRID>
            
        //     <VISUAL_AESTHETICS>
        //     - Style: {$design['image_style']}
        //     - Mood: {$design['visual_mood']}
        //     - Human Presence: {$design['human_presence']}
        //     - Perspective: {$design['content_angle']}
        //     </VISUAL_AESTHETICS>
            
        //     <FINAL_COMPOSITION_LOGIC>
        //     Balance the visual weight of the image. Maintain a clear hierarchy. If any element (text or image) overlaps another, adjust the layout to ensure a clean, medical-grade aesthetic. No crowding. No clutter.
        //     </FINAL_COMPOSITION_LOGIC>";
        
        // $prompt = "Create a dark, dramatic, professional book chapter feature image optimized for Instagram portrait posts.

        //     STRICT TEXT RULES:
        //     All text must be written only in correct English.
        //     No random symbols, characters, or distorted letters.
        //     No misspellings.
        //     Use clean professional typography.
        //     Text must be perfectly readable and evenly spaced.
        //     Do NOT render any layout labels, section names, zone numbers, or instruction text in the image.
        //     Only render the exact content text values listed in the PERMITTED TEXT section below.
            
        //     CANVAS:
        //     Portrait layout 1080 × 1350 px (4:5).
        //     Choose a deep, dark, rich background color that best suits the mood and theme of the chapter content.
        //     The chosen background color must be applied consistently across the entire canvas from edge to edge.
        //     Maintain a safe margin on all sides.
        //     No content may touch edges.
            
        //     ---
            
        //     PERMITTED TEXT IN IMAGE (Only these exact texts may appear — nothing else):
        //     1. 'The Carbonated Body'
        //     2. '{$chapter->chapter}: {$chapter->chapter_title}'
        //     3. A 35 to 50 word description generated from the chapter summary
        //     4. 'https://co2body.com/{$affiliate_id}'
            
        //     NO other text, label, heading, number, instruction word, or any other word may appear anywhere in the image.
            
        //     ---
            
        //     LAYOUT STRUCTURE (Top to Bottom):
            
        //     [TOP AREA — Title, 15% of canvas]
        //     Large dominant title text at the very top.
        //     Render only: 'The Carbonated Body'
        //     Style: Very large bold serif or display font, pure white (#FFFFFF), centered.
        //     Font size must be the largest text on the entire canvas.
        //     Below the title draw a thin horizontal golden or amber glowing divider line spanning 60% of canvas width, centered.
            
        //     [UPPER MIDDLE — Chapter heading, 8% of canvas]
        //     Render only: '{$chapter->chapter}: {$chapter->chapter_title}'
        //     Style: Bold, golden-amber color (#F5A623), centered, medium-large font.
        //     No background, sits directly on the canvas background.
            
        //     [MAIN VISUAL — Large illustration, 45% of canvas]
        //     A large, dramatic, high quality digital art illustration representing the themes from this summary: '{$chapter->chapter_summary}'
        //     Style: {$design['image_style']}
        //     Mood: {$design['visual_mood']}
        //     Tone: {$design['human_presence']}
        //     Angle: {$design['content_angle']}
        //     The illustration lighting and color palette must blend naturally and harmoniously into the chosen background color.
        //     The illustration must blend seamlessly into the background with soft edges — no hard borders or white halos.
        //     This illustration must contain absolutely NO text, NO words, NO letters of any kind.
            
        //     [BOTTOM AREA — Two elements side by side, 25% of canvas]
            
        //     LEFT SIDE (35% of canvas width, bottom-right corner):
        //     Place the provided book cover image here exactly as it is.
        //     Do NOT redesign, recolor, filter, alter, or redraw the book cover in any way.
        //     The book cover must appear 100% pixel-identical to the original source image.
        //     Add only a soft subtle glow or drop shadow around it to help it stand out from the background.
        //     The book cover must be fully visible and not cropped.
            
        //     RIGHT SIDE (55% of canvas width):
        //     A semi-transparent rounded rectangle card.
        //     Card background: A slightly lighter or darker shade of the canvas background color with soft opacity and subtle border glow.
        //     Inside this card render a short description of exactly 35 to 50 words.
        //     Generated from this chapter summary: '{$summary}'
        //     Style: Flowing prose, no bullet points, white or very light text, 18-20px, relaxed line height, centered alignment.
        //     The card must NOT overlap the book cover on the right.
            
        //     [FOOTER — Bottom 7% of canvas]
        //     No background change — sits directly on the canvas background.
        //     Render only: 'https://co2body.com/{$affiliate_id}'
        //     Style: White or light colored, small but clearly readable font, centered horizontally.
        //     Must not overlap the book cover or the text card above it.
            
        //     ---
            
        //     ABSOLUTE RULES:
        //     Never render zone names, area labels, numbers, or any instruction text in the image.
        //     Never alter the book cover — it must look exactly as provided.
        //     No element may overlap another element.
        //     The URL in the footer must be fully visible and readable.
        //     The illustration colors and lighting must harmonize with the chosen background.
        //     Clean layout, strong visual hierarchy, generous spacing between all elements.
        //     No crowding, no clutter.
            
        //     If any instruction text, zone label, or layout label appears in the rendered image, that is an error — regenerate until only the permitted content text is visible.";
        
        $prompt = "Create a clean, dramatic, professional book chapter feature image optimized for Instagram portrait posts.

            STRICT TEXT RULES:
            All text must be written only in correct English.
            No random symbols, characters, or distorted letters.
            No misspellings.
            Use clean professional typography.
            Text must be perfectly readable and evenly spaced.
            Do NOT render any layout labels, section names, zone numbers, or instruction text in the image.
            Only render the exact content text values listed in the PERMITTED TEXT section below.
            
            CANVAS:
            Portrait layout 1080 × 1350 px (4:5).
            Choose a background color that naturally matches the mood, tone, and theme of the chapter content and illustration.
            The background color must feel appropriate to the subject — it can be dark, light, warm, cool, vibrant, or muted depending on what suits the content best.
            The chosen background color must be applied consistently across the entire canvas from edge to edge.
            Maintain a safe margin on all sides.
            No content may touch edges.
            
            ---
            
            PERMITTED TEXT IN IMAGE (Only these exact texts may appear — nothing else):
            1. 'The Carbonated Body'
            2. '{$chapter->chapter}: {$chapter->chapter_title}'
            3. A 35 to 50 word description generated from the chapter summary
            4. 'https://co2body.com/{$affiliate_id}'
            
            NO other text, label, heading, number, instruction word, or any other word may appear anywhere in the image.
            
            ---
            
            LAYOUT STRUCTURE (Top to Bottom):
            
            [TOP AREA — Title, 15% of canvas]
            Large dominant title text at the very top.
            Render only: 'The Carbonated Body'
            Style: Very large bold serif or display font, centered.
            Font color must have strong contrast against the chosen background so it is clearly readable.
            Font size must be the largest text on the entire canvas.
            Below the title draw a thin horizontal glowing divider line spanning 60% of canvas width, centered.
            Divider color must complement the chosen background color.
            
            [UPPER MIDDLE — Chapter heading, 8% of canvas]
            Render only: '{$chapter->chapter}: {$chapter->chapter_title}'
            Style: Bold, accent color that complements the background, centered, medium-large font.
            No background, sits directly on the canvas background.
            
            [MAIN VISUAL — Large illustration, 45% of canvas]
            A large, dramatic, high quality digital art illustration representing the themes in this text: '{$imageText}'
            Style: {$design['image_style']}
            Mood: {$design['visual_mood']}
            Tone: {$design['human_presence']}
            Angle: {$design['content_angle']}
            The illustration lighting and color palette must blend naturally and harmoniously into the chosen background color.
            The illustration must blend seamlessly into the background with soft edges — no hard borders or white halos.
            This illustration must contain absolutely NO text, NO words, NO letters of any kind.
            
            [BOTTOM AREA — Two elements side by side, 25% of canvas]
            
            LEFT SIDE (35% of canvas width, bottom-right corner):
            Place the provided book cover image here exactly as it is.
            Do NOT redesign, recolor, filter, alter, or redraw the book cover in any way.
            The book cover must appear 100% pixel-identical to the original source image.
            Add only a soft subtle glow or drop shadow around it to help it stand out from the background.
            The book cover must be fully visible and not cropped.
            
            RIGHT SIDE (55% of canvas width):
            A semi-transparent rounded rectangle card.
            Card background: A slightly lighter or darker shade of the canvas background with soft opacity and subtle border glow.
            Inside this card render this EXACT text, verbatim and unchanged - the user has already reviewed and approved this precise wording, do not summarize, paraphrase, shorten, or reword it in any way:
            '{$imageText}'
            Text style: high contrast color against the card background, 18-20px, relaxed line height, centered alignment.
            The card must NOT overlap the book cover on the right.
            
            [FOOTER — Bottom 7% of canvas]
            No background change — sits directly on the canvas background.
            Render only: 'https://co2body.com/{$affiliate_id}'
            Style: High contrast against the background, small but clearly readable font, centered horizontally.
            Must not overlap the book cover or the text card above it.
            
            ---
            
            ABSOLUTE RULES:
            Never render zone names, area labels, numbers, or any instruction text in the image.
            Never alter the book cover — it must look exactly as provided.
            No element may overlap another element.
            The URL in the footer must be fully visible and readable.
            The background color must feel natural and fitting to the chapter content — not always dark, not always light.
            The illustration colors and lighting must harmonize with the chosen background.
            All text colors must have strong contrast against their backgrounds for readability.
            Clean layout, strong visual hierarchy, generous spacing between all elements.
            No crowding, no clutter.
            
            If any instruction text, zone label, or layout label appears in the rendered image, that is an error — regenerate until only the permitted content text is visible.";
          
        return ['prompt'=>$prompt,'imagepath'=>$imagepath];
    }

    private function getSystemInstruction($chapter, $textFormat = 'paragraph')
    {
        // $systemInstruction = "
        //     You are a professional social media content engine for a science book titled 'The Carbonated Body'.

        //     Your task is to generate SHORT-FORM social media content for Instagram and TikTok posts, 
        //     based on provided user prompt using chapter content:'$chapter->content'
        
        //     IMPORTANT RULES (MANDATORY):
        //     - Respond with RAW JSON only.
        //     - Do not include any introductory text, markdown formatting (like ```json)
        //     - First character must be { and last character must be }.
        //     - Do NOT include markdown, explanations, comments, or extra text.
        //     - Do not use medical or clinical language.
        //     - Do not use words like cure, treat, heal, prevent, fix, reduce symptoms, improve condition.
        //     - Content must be educational only, not medical advice.
        //     - Assume all text will be rendered on a 9:16 image — keep text concise and readable.
        //     - Do NOT invent facts beyond the chapter content provided.

        //     CONTENT CONSTRAINTS:
        //     - Title: max 42 characters
        //     - Bullets: 3–5 bullets, each max 68 characters each bullet should be unique
        //     - Caption: max 2 short sentences, emojis allowed
        //     - Script: max 5 short spoken lines (voiceover-friendly)

        //     STYLE RULES:
        //     - Tone depends on provided angle and audience
        //     - Clear, confident, non-sensational language
        //     - No em dashes (—)
        //     - Avoid hype words like 'miracle', 'secret', 'hack'
        //     - No hype, no guarantees

        //     OUTPUT FORMAT (JSON ONLY):
        //     {
        //         'title': 'Short headline',
        //         'caption': '',
        //         'script': '',
        //         'hashtags': ''
        //     }
        //     Note:- You MUST respond ONLY in JSON format Do not include any introductory text, markdown formatting (like ```json), or explanations
        // ";

        $imageTextFormatInstruction = $textFormat === 'bullet_points'
            ? "3-5 short bullet points as a single string, one per line, each line starting with '• '"
            : "3-5 short, complete sentences in flowing paragraph form";

        $systemInstruction = "You are a professional social media content engine for a science book titled 'The Carbonated Body'.
        Your task is to generate SHORT-FORM social media content for Instagram and TikTok posts,
        based on provided user prompt using chapter content:'$chapter->content'
        IMPORTANT RULES (MANDATORY):
        -Do NOT invent facts beyond the chapter content provided.
        -Content must be educational only, not medical advice.

        You MUST respond ONLY in JSON format Do not include any introductory text, no markdown formatting (like ```json), or explanations,with the following keys:
        'caption': A catchy caption with emojis, for the social media post description. This is NOT rendered on the image itself.
        'hashtags': A string of 10-15 trending hashtags as comma separated values (include # symbol).
        'summary': A 250-300 words chapter content summary from provided chapter content above without manupulating the meaning of chapter.
        'image_text': The EXACT text that will be rendered visually on the image itself - this is what the user reviews and approves before the image is generated, and the image generator will render it verbatim. Must be 35-50 words total, formatted as {$imageTextFormatInstruction}. Use simple common English words, perfect spelling and grammar, complete sentences only. No hashtags, no emojis, no special symbols, no filler text.
        'title': A scroll-stopping headline.";
        
        // 'script': A short script.
        return $systemInstruction;
    }


    public function removeGeminiWatermark(Request $request)
    {
         // Ensure storage directory exists
        Storage::disk('public')->makeDirectory('temp');
        Storage::disk('public')->makeDirectory('uploads/gemini-crop');

        /**
         * STEP 1: Get image from file or URL
         */
        if ($request->hasFile('image')) {

            // Case 1: Uploaded file
            $file = $request->file('image');
            $imagePath = $file->store('temp', 'public');
            $imagePath = Storage::disk('public')->path($imagePath);

        } elseif ($request->filled('image')) {

            // Case 2: Image URL
            $imageUrl = $request->input('image');

            // Validate URL
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                return response()->json(['error' => 'Invalid image URL'], 422);
            }

            $imageContent = @file_get_contents($imageUrl);
            if ($imageContent === false) {
                return response()->json(['error' => 'Unable to download image'], 422);
            }

            $extension = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';
            $fileName = 'temp/' . uniqid('img_') . '.' . $extension;

            Storage::disk('public')->put($fileName, $imageContent);
            $imagePath = Storage::disk('public')->path($fileName);

        } else {
            return response()->json(['error' => 'No image file or URL provided'], 422);
        }
     
        //function of crop image
        $outputPath = Storage::disk('public')->path('uploads/gemini-crop/gemini-crop'.time().'.png');

        // $imagePath = Storage::disk('public')->path('assets/gemini(1).png');
        $cropPercent = 0.06;

        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) return false;

        $width  = $imageInfo[0];
        $height = $imageInfo[1];
        $mime   = $imageInfo['mime'];

        // 2. Load image based on type
        switch ($mime) {
            case 'image/jpeg': $src = imagecreatefromjpeg($imagePath); break;
            case 'image/png':  $src = imagecreatefrompng($imagePath);  break;
            case 'image/webp': $src = imagecreatefromwebp($imagePath); break;
            default: return false;
        }

        // 3. Calculate Crop Area (Trimming the edges)
        $xOffset = $width * $cropPercent;
        $yOffset = $height * $cropPercent;
        $newW    = $width - (2 * $xOffset);
        $newH    = $height - (2 * $yOffset);

        // 4. Create new canvas and crop
        $dest = imagecreatetruecolor($newW, $newH);
        
        // Preserve transparency if it's a PNG
        if ($mime == 'image/png') {
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
        }

        imagecopyresampled($dest, $src, 0, 0, $xOffset, $yOffset, $newW, $newH, $newW, $newH);

        // 5. Save/Output
        switch ($mime) {
            case 'image/jpeg': imagejpeg($dest, $outputPath, 90); break;
            case 'image/png':  imagepng($dest, $outputPath);     break;
            case 'image/webp': imagewebp($dest, $outputPath);    break;
        }

        imagedestroy($src);
        imagedestroy($dest);
        
        // Delete temp image safely (only if it's a temp file)
        $relativeTempPath = str_replace(
            Storage::disk('public')->path(''),
            '',
            $imagePath
        );

        Storage::disk('public')->delete($relativeTempPath);

        return response()->json([
            'status' => true,
            'image_name' => basename($outputPath),
            'image'  => asset('storage/uploads/gemini-crop/' . basename($outputPath))
        ]);
    }

    //backup code  for gemini image code 
    // private function generateGeminiFallbackAIImage($prompt, $imagepath = null)
    // {
    //     try{
                // 2. Execute the Request
                        //model : gemini-2.5-flash-image
            //     $response = Http::timeout(90)
            //         ->retry(2, 2000, function ($exception) {
            //             return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            //         })
            //         ->withHeaders([
            //             'Content-Type' => 'application/json',
            //         ])
            //         ->post(
            //             "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent?key={$apiKey}",
            //             [
            //                 "contents" => [
            //                     ["parts" => [
            //                         [
            //                             "inline_data" => [
            //                                 "mime_type" => "image/png",
            //                                 "data" => $imagepath,
            //                             ]
            //                         ],
            //                         ["text" => $prompt]
                                    
            //                         ]
                                
            //                     ]
            //                 ],
            //                 "generationConfig" => [
            //                     "imageConfig" => [
            //                         "aspectRatio" => "1:1",
            //                         "imageSize" => "SD",
            //                     ]
            //                 ]
            //             ]
            //         );
            //     // dd($response);
            //     if ($response->failed()) {
            //         // This will show you the ACTUAL reason (e.g., "Invalid model name" or "Safety block")  
            //         $errorData = $response->json();
            //         $errorMessage = $errorData['error']['message'] ?? 'Unknown Gemini API Error for image generation';
            //         // return $this->sendError("Gemini Image API Error: " . $errorMessage);
            //         return [
            //             'success' => false,
            //             'image_url' =>'',
            //             'error' => 'Currently this model server is under high load. Try another AI model or upload manually.'
            //         ];
                

            //     }

            //     if ($response->successful()) {
            //         // $data = $response->json();

            //         // // 3. Extract Base64 and save as URL
                    
            //         $data = json_decode($response->body(), true);
            //         if (!$data) {
            //             \Log::error('Invalid JSON from Gemini Image API', [
            //                 'body' => $response->body()
            //             ]);
            //             // return $this->sendError('Invalid JSON from Gemini', [], 500);
            //             return [
            //                 'success' => false,
            //                 'image_url' => '',
            //                 'error' => 'Currently this model server is under high load. Try another AI model or upload manually.'
            //             ];
            //         }

            //         // $base64 = data_get($data, 'candidates.0.content.parts.0.inline_data.data');
            //         $base64 = data_get($data, 'candidates.0.content.parts.0.inlineData.data');
            //         if (!$base64) {
            //             \Log::error('No image data returned', $data);
            //             // return $this->sendError('No image generated', $data, 500);
            //             return [
            //                 'success' => false,
            //                 'image_url' => '',
            //                 'error' => 'Currently this model server is under high load. Try another AI model or upload manually.'
            //             ];
            //         }
            //         // $mimeType = data_get($data, 'candidates.0.content.parts.0.inline_data.mime_type', 'image/png');
            //         $mimeType = data_get($data, 'candidates.0.content.parts.0.inlineData.mimeType', 'image/png');
            //         // This creates a "URL" that contains the image itself
            //         $dataUrl = "data:{$mimeType};base64,{$base64}";
            //         // return $dataUrl;
            //         return [
            //             'success' => true,
            //             'image_url' => $dataUrl,
            //             'error' => null
            //         ];
            //     }
            //     // return response()->json(['error' => 'Generation Failed'], 500);
            // } catch (\Exception $e) {
            //     Log::error('Gemini image failed', [
            //         'status' => $response?->status(),
            //         'body' => $response?->body(),
            //         'error' => $e->getMessage()
            //     ]);
            //     // return $this->sendError('Error generating gemini image', ['error' => $e->getMessage()], 500);
            //     return [
            //         'success' => false,
            //         'image_url' => '',
            //         'error' => 'Currently this model server is down. Try another AI model or upload manually.'
            //     ];
            // }
    // }

    

}
