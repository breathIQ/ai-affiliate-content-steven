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

class AiPostGenerationController extends ResponseController
{
    public function generateContent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'chapter' => 'required|exists:chapters,id',
            'model'   => 'required|string', // e.g., 'gpt-4-turbo', 'claude-3-haiku-20240307'
            'prompt'  => 'required|string',

             // new fields
            'post_type' => 'required|in:carousel,single',

            'slides' => 'required_with:slide_texts|integer|min:1|max:4',

            'slide_texts' => 'nullable|array',
            'slide_texts.*' => 'required|string',

            // design only if slide_texts exists
            'design' => 'required_with:slide_texts|array',

            'design.overlay_color' => 'required_with:slide_texts|string',
            'design.text_placement' => 'required_with:slide_texts|string',
            'design.font_family' => 'required_with:slide_texts|string',
            'design.font_size' => 'required_with:slide_texts|string',
            'design.font_weight' => 'required_with:slide_texts|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->first());
        }
        // dd($request->all());
        // $chapter = Chapter::find($request->chapter);
        $chapter = Chapter::join('book_chapters', 'chapters.chapter', '=', 'book_chapters.chapter')
            ->where('chapters.id', $request->chapter)
            ->select('chapters.id', 'chapters.chapter', 'book_chapters.chapter_title as chapter_title')
            ->first();
        $modelChoice = $request->model;
        $userPrompt = $request->prompt;

        $postType    = $request->post_type;
        $slidesCount = (int) $request->slides;
        $slideTexts  = $request->slide_texts ?? [];
        $design      = $request->design ?? null;

        // Final prompt jo AI ko jayega
        // $finalPrompt = "Chapter: {$chapter->content}\n\nTask: {$userPrompt}";
        $finalPrompt = "Task: {$userPrompt}";

        if (str_contains($modelChoice, 'gpt')) {
            return $this->generateWithOpenAI($modelChoice, $finalPrompt,$chapter,$postType,$slidesCount,$slideTexts,$design);
        } elseif (str_contains($modelChoice, 'claude')) {
            return $this->generateWithClaude($modelChoice, $finalPrompt,$chapter,$postType,$slidesCount,$slideTexts,$design);
        }

        return $this->sendError('Invalid Model Selected', [], 400);
    }

    private function generateWithOpenAI($model, $prompt,$chapter,$postType,$slidesCount,$slideTexts,$design)
    {
        
      // AI ko specific format sikhane ke liye prompt
        $systemInstruction = "You are an expert social media content creator. 
        Based on the chapter content '{$chapter->content}', generate a high-quality post.
        You MUST respond ONLY in JSON format Do not include any introductory text, markdown formatting (like ```json), or explanations,with the following keys:
        'caption': A catchy caption with emojis.
        'hashtags': A string of 10-15 trending hashtags as comma separated values.
        'script': A short video script.
        'title': A scroll-stopping headline.";

        try {
            $result = OpenAI::chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemInstruction],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
            // dd($result);
            $rawContent = $result->choices[0]->message->content;
            //Error Handling: Check if rawContent is empty
            if (!$rawContent) {
                throw new \Exception("AI returned empty content.");
            }

            $structuredData = json_decode($rawContent, true);
            if (is_null($structuredData)) {
                // Agar JSON invalid hai toh manually handle karein ya error dein
                throw new \Exception("Invalid JSON format received from AI.");
            }

            /** ------------------ IMAGE GENERATION ------------------ */
            $images = $this->getImages($structuredData['caption'], $postType, $slidesCount, $slideTexts, $design);

            /** ------------------ RESPONSE ------------------ */
            return $this->sendResponse([
                'caption' => $structuredData['caption'],
                'hashtags' => $structuredData['hashtags'],
                'script' => $structuredData['script'],
                'title' => $structuredData['title'],
                'model' => $model,
                'post_type' => $postType,
                'slides' => $slidesCount,
                'images' => $images,
                'chapter' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $chapter->chapter),
                'chapter_title' => $chapter->chapter_title,
                'chapter_id' => $chapter->id,
                'ai_prompt' => $prompt,
                // 'generated_image' => $imageUrl,
            ], 'Content generated successfully', 200);
        } catch (\Exception $e) {
            return $this->sendError('Error generating content', ['error' => $e->getMessage()], 500);
        }
    }


    private function generateWithClaude($model, $prompt, $chapter, $postType, $slidesCount, $slideTexts, $design)
    {
        // System Instruction for structured output like your UI
        $systemInstruction = "You are an expert social media content creator. 
        Based on the chapter content '{$chapter->content}', generate a high-quality post.
        You MUST respond ONLY in valid JSON format Do not include any introductory text, markdown formatting (like ```json), or explanations, with these exact keys:
        'caption': A catchy caption with emojis.
        'hashtags': A string of 10-15 trending hashtags as comma separated values.
        'script': A short video script.
        'title': A scroll-stopping headline.";
            // dd(Config::get('constant.claud_keys.key'));
        try {
            $response = Http::withHeaders([
                'x-api-key' => Config::get('constant.claud_keys.key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 2048, // Increased for long scripts
                'system' => $systemInstruction, // System prompt should be here, not in messages
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
            ]);

           
            // 1. Check for API Errors (like 401, 400, 500)
            if ($response->failed()) {
                $errorData = $response->json();
                $errorMessage = $errorData['error']['message'] ?? 'Unknown Claude API Error';
                throw new \Exception("Claude API Error: " . $errorMessage);
            }

            $resData = $response->json();

            // 2. Safe access to content
            if (!isset($resData['content'][0]['text'])) {
                throw new \Exception("Unexpected API response structure.");
            }

            $rawContent = $resData['content'][0]['text'];
            $structuredData = json_decode($rawContent, true);
            // dd($structuredData);
            if (is_null($structuredData)) {
                throw new \Exception("Invalid JSON format received from AI.");
            }

            // --- NEW: Image Generation --- 
            $images = $this->getImages($structuredData['caption'], $postType, $slidesCount, $slideTexts, $design);

            // 3. Send successful response to React
            return $this->sendResponse([
                'caption' => $structuredData['caption'] ?? '',
                'hashtags' => $structuredData['hashtags'] ?? '',
                'script' => $structuredData['script'] ?? '',
                'title' => $structuredData['title'] ?? '',
                'model' => $model,  
                'post_type' => $postType,
                'slides' => $slidesCount,
                'images' => $images,
                'chapter' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $chapter->chapter),
                'chapter_title' => $chapter->chapter_title,
                'chapter_id' => $chapter->id,
                'ai_prompt' => $prompt,
            ], 'Content generated successfully', 200);

        } catch (\Exception $e) {
            return $this->sendError('Error generating content', ['error' => $e->getMessage()], 500);
        }
    }

    private function generateAIImage($prompt, $model = 'dall-e-3')
    {
        $response = OpenAI::images()->create([
            'model' => $model,
            'prompt' => $prompt,
            'size' => '1024x1024',
            'quality' => 'standard',
        ]);

        return $response->data[0]->url; // Temporary URL from OpenAI
    }

    private function buildSlideImagePrompt($title, $slideText, $design, $slideNumber)
    {
        // $designPrompt = '';

        // if (!empty($design)) {
        //     $designPrompt = "
        //     Design requirements:
        //     - Text placement: {$design['text_placement']}
        //     - Font style: {$design['font_family']} font, weight {$design['font_weight']}
        //     - Text size hierarchy: {$design['font_size']}
        //     - Overlay color theme: {$design['overlay_color']}
        //     ";
        // }

        // return "
        //     Create a high-quality image.

        //     Image concept:
        //     '{$title}'

        //     The image MUST contain the following overlay text exactly:
        //     '{$slideText}'

        //     {$designPrompt}

        //     Additional instructions for text:
        //     - Use clean, bold, sans-serif fonts only (e.g., Arial, Helvetica, Poppins)
        //     - Text must be in clear, US English characters
        //     - No distorted, blurry, or handwritten text
        //     - Text color must contrast strongly against the background for maximum readability
        //     - No artistic or decorative fonts
        //     - The overlay text should be centered and spaced for easy reading

        //     Additional rules:
        //     - Clean, modern, professional social media design
        //     - No watermark, no logos, no extra text
        //     - 4:5 aspect ratio
        // ";

        return "
            Create a high-quality minimal background image No text, no letters, no numbers, no symbols.
            Simple abstract background.High contrast, professional style (NOT an abstract illustration).

            PRIMARY OBJECTIVE (DO NOT IGNORE):
            The image must clearly and legibly display the following text EXACTLY as written, with no changes, no paraphrasing, and no missing words:

            TEXT TO DISPLAY (EXACT):
            '{$slideText}'

            DESIGN STYLE:
            - Clean, modern, professional image
           
            - Font family: {$design['font_family']}
            - Font weight: {$design['font_weight']}
            - Text hierarchy: {$design['font_size']}
            - Text placement: {$design['text_placement']}
            - Strong contrast between text and background
            - Overlay color theme: {$design['overlay_color']}

            BACKGROUND & IMAGE CONCEPT:
            - Visual concept inspired by: '{$title}'
            - Background must be minimal and must NOT overpower the text
            - Background exists only to support readability

            STRICT NEGATIVE RULES:
            - No handwritten fonts
            - No decorative or artistic fonts
            - No distorted, warped, blurry, or curved text
            - No extra text, captions, watermarks, logos, or symbols
            - Do NOT rewrite, summarize, or reinterpret the text

            FORMAT:
            - Aspect ratio: 4:5
            - High resolution

        ";
    }

    private function getImages($caption, $postType, $slidesCount, $slideTexts, $design)
    {
        $images = [];

        if ($postType === 'carousel') {
            
            for ($i = 0; $i < $slidesCount; $i++) {

                $slideText = $slideTexts[$i] ?? null;

                $imagePrompt = $this->buildSlideImagePrompt(
                    $caption,
                    $slideText,
                    $design,
                    $i + 1
                );

                $images[] = [
                    'slide' => $i + 1,
                    'text'  => $slideText,
                    'image_url' => $this->generateAIImage($imagePrompt),
                ];
            }

        } else {
            // Single post
            $slideText = $slideTexts[0] ?? null;

            if ($slideText) {
                // Single with text
                $imagePrompt = $this->buildSlideImagePrompt(
                    $caption,
                    $slideText,
                    $design,
                    1
                );
            } else {
                // Single without text
                $imagePrompt = "
                Create a high-quality social media post image.

                Image concept:
                '{$caption}'

                Design style:
                - Modern
                - Clean
                - Professional
                - Social media optimized
                - No text overlay
                ";
            }

            $images[] = [
                'slide' => 1,
                'text'  => $slideText,
                'image_url' => $this->generateAIImage($imagePrompt),
            ];
        }

        return $images;
    }

}
