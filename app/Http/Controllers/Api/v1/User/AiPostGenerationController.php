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


class AiPostGenerationController extends ResponseController
{
    public function generateContent(Request $request)
    {
    //    dd(Config::get('constant.open_ai_keys.key'));
        // $this->imageGeneration();
        // dd("hello");
        $validator = Validator::make($request->all(), [
            'chapter' => 'required|exists:chapters,id',
            'model'   => 'required|string', // e.g., 'gpt-4-turbo', 'claude-3-haiku-20240307'
            'prompt'  => 'required|string',

             // new fields
            'post_type' => 'required|in:carousel,single',

            'slides' => 'required|numeric|min:1|max:4',

            // design only if slide_texts exists
            'design' => 'required|array',

            'design.image_style' => 'required|string',
            'design.content_angle' => 'required|string',
            'design.human_presence' => 'required|string',
            'design.visual_mood' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->first());
        }
        // dd($request->all());
        // $chapter = Chapter::find($request->chapter);
        $chapter = Chapter::join('book_chapters', 'chapters.chapter', '=', 'book_chapters.chapter')
            ->where('chapters.id', $request->chapter)
            ->select('chapters.id', 'chapters.chapter','chapters.content', 'book_chapters.chapter_title as chapter_title')
            ->first();
        $modelChoice = $request->model;
        $userPrompt = $request->prompt;

        $postType    = $request->post_type;
        $slidesCount = (int) $request->slides;
        $design      = $request->design ?? null;

        // Final prompt jo AI ko jayega
        // $finalPrompt = "Chapter: {$chapter->content}\n\nTask: {$userPrompt}";
        $finalPrompt = "Task: {$userPrompt}";

        if (str_contains($modelChoice, 'gpt')) {
            return $this->generateWithOpenAI($modelChoice, $finalPrompt,$chapter,$postType,$slidesCount,$design);
        } elseif (str_contains($modelChoice, 'claude')) {
            return $this->generateWithClaude($modelChoice, $finalPrompt,$chapter,$postType,$slidesCount,$design);
        }

        return $this->sendError('Invalid Model Selected', [], 400);
    }

    private function generateWithOpenAI($model, $prompt,$chapter,$postType,$slidesCount,$design)
    {
        $images = $this->generateGeminiImage($chapter, $postType, $slidesCount, $design);
        dd($images);
      // AI ko specific format sikhane ke liye prompt
        $systemInstruction = $this->getSystemInstruction($chapter);
        
        try {
           
            $result = OpenAI::chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemInstruction],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
            
            $rawContent = $result->choices[0]->message->content;
            //Error Handling: Check if rawContent is empty
            if (!$rawContent) {
                throw new \Exception("AI returned empty content.");
            }
            // dd($rawContent);
            $structuredData = json_decode($rawContent, true);
            if (is_null($structuredData)) {
                // Agar JSON invalid hai toh manually handle karein ya error dein
                throw new \Exception("Invalid JSON format received from AI.");
            }
            // dd($structuredData);
            /** ------------------ IMAGE GENERATION ------------------ */
            // $images = $this->getImages($structuredData['caption'], $postType, $slidesCount, $slideTexts, $design);
            $images = $this->getImages($chapter, $postType, $slidesCount, $design);

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
        } catch (ErrorException $e) {
            // return $this->sendError('Error generating content', ['error' => $e->getMessage()], 500);
            if (str_contains($e->getMessage(), 'rate limit')) {
                sleep(2); // wait before retry
            }

            return $this->sendError('Error generating content', ['error' => $e->getMessage()], 500);
        }
    }


    private function generateWithClaude($model, $prompt, $chapter, $postType, $slidesCount, $design)
    {
        // System Instruction for structured output like your UI
        $systemInstruction = $this->getSystemInstruction($chapter);
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
            // $images = $this->getImages($structuredData['caption'], $postType, $slidesCount, $slideTexts, $design);
            //$images = $this->getImages($chapter, $postType, $slidesCount, $design);

            // 3. Send successful response to React
            return $this->sendResponse([
                'caption' => $structuredData['caption'] ?? '',
                'hashtags' => $structuredData['hashtags'] ?? '',
                'script' => $structuredData['script'] ?? '',
                'title' => $structuredData['title'] ?? '',
                'model' => $model,  
                'post_type' => $postType,
                'slides' => $slidesCount,
                //'images' => $images,
                'chapter' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $chapter->chapter),
                'chapter_title' => $chapter->chapter_title,
                'chapter_id' => $chapter->id,
                'ai_prompt' => $prompt,
            ], 'Content generated successfully', 200);

        } catch (\Exception $e) {
            return $this->sendError('Error generating content', ['error' => $e->getMessage()], 500);
        }
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
    //             'timeout' => 120, // ⬅️ IMPORTANT
    //         ]);

    //         Log::info('image function response', [$response]);
    //         return $response->data[0]->url; // Temporary URL from OpenAI

    //     } catch (\Exception $e) {
    //         return $this->sendError('Error generating image', ['error' => $e->getMessage()], 500);
    //     }

    // }

    private function generateAIImage($prompt)
    {
        $apiKey = Config::get('constant.open_ai_keys.key');
        $imagePath = Storage::disk('public')->path('assets/cover-image.png');
        
        $ch = curl_init("https://api.openai.com/v1/images/edits");

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$apiKey}"
        ]);

        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            "model" => "gpt-image-1",
            "prompt" => $prompt,
            "image" => new CURLFile($imagePath, "image/png"),
            "size" => "1024x1536"
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        // cleanup
        $result = json_decode($response, true);
        // dd($result);
        $base64 = $result['data'][0]['b64_json'];
        // Create data URL
        $imageUrl = 'data:image/png;base64,' . $base64;
        
        return $imageUrl;
        
    }

    private function buildSlideImagePrompt($chapter, $design, $slideNumber)
    {
        $imagePath = Storage::disk('public')->path('assets/cover-image.png');
        
        $prompt = "A professional 9:16 vertical infographic layout for a medical educational post. 
            TITLE: 'The Carbonated Body' (Large, elegant serif font at the top).
            SUBTITLE: 'Chapter {$chapter->chapter}: {$chapter->chapter_title}' (Positioned below the title).

            VISUAL CENTERPIECE: 
            Visual style: {$design['image_style']}
            Mood: {$design['visual_mood']}
            Audience tone: {$design['human_presence']}
            Angle: {$design['content_angle']}
          

            CONTENT SECTION:
            On a clean, semi-transparent overlay or clear negative space, include 5 concise bullet points summarizing these key concepts: {$chapter->chapter_title}, content angle: {$design['content_angle']}.

            THUMBNAIL ELEMENT:
            In the bottom-right corner, place the EXACT provided book cover image from {$imagePath} as a static thumbnail.
            Do NOT redesign, recolor, restyle, reinterpret, or regenerate the cover.
            Preserve the original text, colors, typography, proportions, and layout exactly as provided.
            The cover must be used as-is, unchanged, and scaled down only.

            TECHNICAL SPECIFICATIONS:
            - Aspect Ratio: 9:16 (Vertical).
            - Composition: High-end medical journal aesthetic.
            - Layout: Top-heavy text, center visual, bottom-right thumbnail.
            - Ensure all text is legible and centered within the 1080x1920 frame with safe-zone margins to prevent cropping.
            
            IMPORTANT LAYOUT RULES:
            - Use a 2:3 vertical layout (1024x1536).
            - Keep all text within safe margins (at least 12% padding top and bottom).
            - Title must be fully visible at the top.
            - Book cover thumbnail must be fully visible at the bottom-right.
            - No cropping or edge-clipped text
            ";

        return $prompt;
    }

    private function getImages($chapter, $postType, $slidesCount, $design)
    {
        // dd($design);
        $images = [];
        
        if ($postType === 'carousel') {
            
            for ($i = 0; $i < $slidesCount; $i++) {

                $imagePrompt = $this->buildSlideImagePrompt(
                    $chapter,
                    $design,
                    $i + 1
                );

                $images[] = [
                    'slide' => $i + 1,
                    'image_url' => $this->generateAIImage($imagePrompt),
                ];

                 sleep(3); // REQUIRED (2–5 seconds)
            }

        } else {
            // Single post
            $imagePrompt = $this->buildSlideImagePrompt(
                $chapter,
                $design,
                1
            );
           
            $images[] = [
                'slide' => 1,
                'image_url' => $this->generateAIImage($imagePrompt),
            ];
        }

        return $images;
    }


    public function generateGeminiImage($chapter, $postType, $slidesCount, $design) 
    {
        $imagepath = Storage::disk('public')->path('assets/cover-image.png');
        
        $apiKey = Config::get('constant.gemini_keys.key');
        //    dd($apiKey);

        $prompt = "Create a clean, professional medical infographic image in a 1:1 square format optimized for Instagram posts.

                IMPORTANT:
                Leave a wide, uniform blank margin on all four sides of the image.
                This margin should remain completely empty with no text, no graphics, no frames, and no decorative elements.
                The blank margin acts as a safety zone for a watermark or logo.

                All visible content must be placed strictly inside a centered inner safe area.

                Content to include inside the safe area:
                - A semi-transparent illustration with a highlighted {$chapter->chapter_title}
                - Title at the top: 'The Carbonated Body'
                - SUBTITLE: '{$chapter->chapter}: {$chapter->chapter_title}' (Positioned below the title)
                - On a clean, semi-transparent overlay or clear negative space, include 5 concise bullet points summarizing key concepts from a {$design['content_angle']} about {$chapter->chapter_title}
                - Provide plenty of breathing space between the content and the blank margins

                THUMBNAIL ELEMENT:
                - In the bottom-left corner, place the EXACT cover image :{$imagepath} as a static thumbnail.
                - Do NOT redesign, recolor, restyle, reinterpret, or regenerate the cover.
                - Preserve the original text, colors, typography, proportions, and layout exactly as provided.
                - The cover must be used as-is, unchanged, and scaled down only.

                VISUAL CENTERPIECE: 
                Visual style: {$design['image_style']}
                Mood: {$design['visual_mood']}
                Audience tone: {$design['human_presence']}
                Angle: {$design['content_angle']}

                Ensure balanced composition and high clarity suitable for Instagram viewing without losing any important content.";

              dd($imagepath,$prompt); 
        try{
            // 2. Execute the Request

            $response = Http::timeout(90)
                ->retry(2, 2000, function ($exception) {
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException;
                })
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent?key={$apiKey}",
                    [
                        "contents" => [
                            ["parts" => [["text" => $prompt]]]
                        ],
                        "generationConfig" => [
                            "imageConfig" => [
                                "aspectRatio" => "1:1",
                                "imageSize" => "SD",
                            ]
                        ]
                    ]
                );
                
            if ($response->failed()) {
                // This will show you the ACTUAL reason (e.g., "Invalid model name" or "Safety block")
                // dd($apiKey,$response->json()); 

            }

            if ($response->successful()) {
                $data = $response->json();
                
                // 3. Extract Base64 and save as URL
                // $base64 = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'];
                // $imageName = 'insta_' . uniqid() . '.png';
                // Storage::disk('public')->put("posts/{$imageName}", base64_decode($base64));

                // return response()->json([
                //     'status' => 'success',
                //     'image_url' => asset("storage/posts/{$imageName}")
                // ]);

                $base64 = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'];
                $mimeType = $data['candidates'][0]['content']['parts'][0]['inlineData']['mimeType'] ?? 'image/png';

                // This creates a "URL" that contains the image itself
                $dataUrl = "data:{$mimeType};base64,{$base64}";

                // return response()->json([
                //     'status' => 'success',
                //     'image_url' => $dataUrl
                // ]);
                return $dataUrl;
            }
            // return response()->json(['error' => 'Generation Failed'], 500);
        } catch (\Exception $e) {
            Log::error('Gemini image failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return $this->sendError('Error generating content', ['error' => $e->getMessage()], 500);
        }

        
    }

    private function getSystemInstruction($chapter)
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
        //         'bullets': [],
        //         'caption': '',
        //         'script': '',
        //         'cta': '',
        //         'hashtags': ''
        //     }
        //     Note:- You MUST respond ONLY in JSON format Do not include any introductory text, markdown formatting (like ```json), or explanations
        // ";

        $systemInstruction = "You are a professional social media content engine for a science book titled 'The Carbonated Body'. 
        Your task is to generate SHORT-FORM social media content for Instagram and TikTok posts, 
        based on provided user prompt using chapter content:'$chapter->content_title'
        You MUST respond ONLY in JSON format Do not include any introductory text, markdown formatting (like ```json), or explanations,with the following keys:
        'caption': A catchy caption with emojis.
        'hashtags': A string of 10-15 trending hashtags as comma separated values.
        'script': A short script.
        'title': A scroll-stopping headline.";

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
    

}
