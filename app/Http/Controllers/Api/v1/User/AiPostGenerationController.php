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

class AiPostGenerationController extends ResponseController
{
    public function generateContent(Request $request)
    {
    //    dd(Config::get('constant.open_ai_keys.key'));
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
            ->select('chapters.id', 'chapters.chapter','chapters.content', 'book_chapters.chapter_title as chapter_title')
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
        $systemInstruction = $this->getSystemInstruction($chapter);

        // $images = $this->getImages($chapter, $postType, $slidesCount, $slideTexts, $design);
        // dd($images);
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
            // dd($rawContent);
            $structuredData = json_decode($rawContent, true);
            if (is_null($structuredData)) {
                // Agar JSON invalid hai toh manually handle karein ya error dein
                throw new \Exception("Invalid JSON format received from AI.");
            }
            // dd($structuredData);
            /** ------------------ IMAGE GENERATION ------------------ */
            // $images = $this->getImages($structuredData['caption'], $postType, $slidesCount, $slideTexts, $design);
            $images = $this->getImages($chapter, $postType, $slidesCount, $slideTexts, $design);

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
            //$images = $this->getImages($chapter, $postType, $slidesCount, $slideTexts, $design);

            // 3. Send successful response to React
            return $this->sendResponse([
                'caption' => $structuredData['caption'] ?? '',
                'hashtags' => $structuredData['hashtags'] ?? '',
                'script' => $structuredData['script'] ?? '',
                'title' => $structuredData['title'] ?? '',
                'model' => $model,  
                'post_type' => $postType,
                'slides' => $slidesCount,
                'images' => [],//$images,
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
        Log::info('Call function count');
        $response = OpenAI::images()->create([
            'model' => $model,
            'prompt' => $prompt,
            'size' => '1024x1792', // portrait
            'quality' => 'standard',
        ], [
            'timeout' => 120, // ⬅️ IMPORTANT
        ]);

        return $response->data[0]->url; // Temporary URL from OpenAI
    }

    private function buildSlideImagePrompt($chapter, $slideText, $design, $slideNumber)
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

        // $image = asset(Storage::url('assets/cover-image.png'));
        $image = "https://aiaffiliate.betacvinfotech.com/ai-affiliate-content-steven/public/storage/assets/cover-image.png";

        // $imagePrompt = "
        //     Create a high-quality, professional image that uses the provided image **{$image}** as the background. 
        //     The background should be simple, abstract, and high-contrast but should **not** overpower the text. 
        //     The text must remain clear, legible, and highly visible against the background image.


        //     **PRIMARY OBJECTIVE:**
        //     The image must display the following text exactly as written, without any changes, paraphrasing, or omissions:

        //     **TEXT TO DISPLAY (EXACTLY AS WRITTEN):**
        //     {$slideText}

        //     **DESIGN STYLE:**
        //     - Clean, modern, and professional.
        //     - Font: {$design['font_family']}
        //     - Font Weight: {$design['font_weight']}
        //     - Font Size: {$design['font_size']} (Follow text hierarchy as per the design).
        //     - Text Placement: {$design['text_placement']} (Ensure proper alignment and positioning as specified).
        //     - Contrast: High contrast between text and background to ensure readability.
        //     - Overlay Color Theme: {$design['overlay_color']} (Ensure a consistent theme and balance with the background).

        //     **BACKGROUND & IMAGE CONCEPT:**
        //     - Visual concept inspired by: '{$chapter->chapter_title}'
        //     - The background should be minimalistic and neutral, enhancing readability of the text without distracting from it.
        //     - Avoid any overly detailed or complex imagery that could overshadow the text.
        //     - The background serves only to support the legibility and visibility of the text.

        //     **STRICT NEGATIVE RULES (MUST BE FOLLOWED):**
        //     - **No handwritten fonts.**
        //     - **No decorative or artistic fonts.**
        //     - **No distorted, warped, blurry, or curved text.**
        //     - **No additional text, captions, watermarks, logos, or symbols.**
        //     - **Do not summarize, paraphrase, or reinterpret the text.**

        //     **FORMAT:**
        //     - Aspect ratio: 9:16 (portrait mode).
        //     - Resolution: High-quality resolution suitable for professional presentation.

        // ";
        
        //************2nd prompt******************** */
        // $imagePrompt = "
        //     Create a high-quality image and use the {$image} image as background image.
        //     Simple abstract background.High contrast, professional style (NOT an abstract illustration).

        //     PRIMARY OBJECTIVE (DO NOT IGNORE):
        //     The image must clearly and legibly display the following text EXACTLY as written, with no changes, no paraphrasing, and no missing words:

        //     TEXT TO DISPLAY (EXACT):
        //     '{$slideText}'

        //     DESIGN STYLE:
        //     - Clean, modern, professional image
           
        //     - Font family: {$design['font_family']}
        //     - Font weight: {$design['font_weight']}
        //     - Text hierarchy: {$design['font_size']}
        //     - Text placement: {$design['text_placement']}
        //     - Strong contrast between text and background
        //     - Overlay color theme: {$design['overlay_color']}

        //     BACKGROUND & IMAGE CONCEPT:
        //     - Visual concept inspired by: '{$chapter->chapter_title}'
        //     - Background must be minimal and must NOT overpower the text
        //     - Background exists only to support readability

        //     STRICT NEGATIVE RULES:
        //     - No handwritten fonts
        //     - No decorative or artistic fonts
        //     - No distorted, warped, blurry, or curved text
        //     - No extra text, captions, watermarks, logos, or symbols
        //     - Do NOT rewrite, summarize, or reinterpret the text

        //     FORMAT:
        //     - Aspect ratio: 4:5
        //     - High resolution

        // ";

        $imagePrompt = "
            Portrait 9:16 background image for an Instagram and TikTok educational post related to a science book titled \"The Carbonated Body\".

            Visual style: clinical
            Mood: intelligent, calm, modern, educational
            Audience tone: clinicians
            Angle: clinical

            Subject:
            An abstract, artistic representation of the \"{$chapter->chapter_title}\".
            No explicit organs. No medical procedures. No disease depiction.

            Composition:
            Clean layout with strong visual hierarchy.
            At least 40% negative space reserved for text overlay.
            Center or upper-third visual focus.
            No clutter.

            Color palette:
            Muted scientific tones with subtle cinematic lighting.
            Cool blues and deep shadows with soft glow accents.

            Rendering style:
            High-quality cinematic scientific illustration with depth and atmosphere.
            Not photorealistic.
            Not cartoonish.
            Not surreal.

            Technical requirements:
            Portrait orientation, 9:16 aspect ratio.
            High resolution suitable for 1080x1920 output.

            NO text, NO words, NO letters, NO numbers.
            NO logos, NO branding, NO watermarks.
            NO social media UI elements.
            NO medical equipment, hospitals, syringes, needles.
            NO diseases, injuries, pain, suffering.
            NO labeled organs.
            NO exaggerated anatomy.
            NO before-and-after visuals.
            NO dramatic or sensational imagery.

            Place the Heading on the generated image : \"The Carbonated Body\"
            Place the chapter name on the generated image : \"{$chapter->chapter}\"
            Place the chapter title on the generated image : \"{$chapter->chapter_title}\"
            Write 5-6 Bullet points on image extract from provided chapter content

            Place the provided book cover as a thumbnail on the generated image. cover book is provided here \"{$image}\".   
        ";
        // dd($imagePrompt,$chapter);
         // Write 5-6 Bullet points on image extract from provided chapter content : {$chapter->content}.
            // Include a small book cover thumbnail in the lower corner.
            // Dark blue scientific book cover with a caduceus-like symbol,
            // golden title text, cinematic lighting

        return $imagePrompt;
    }

    private function getImages($chapter, $postType, $slidesCount, $slideTexts, $design)
    {
        $images = [];

        if ($postType === 'carousel') {
            
            for ($i = 0; $i < $slidesCount; $i++) {

                $slideText = $slideTexts[$i] ?? null;

                $imagePrompt = $this->buildSlideImagePrompt(
                    $chapter,
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
                    $chapter,
                    $slideText,
                    $design,
                    1
                );
            } else {
                // Single without text
                $imagePrompt = "
                Create a high-quality social media post image.

                Image concept:
                '{$chapter->chapter_title}'

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


    public function generateSlide(Request $request) 
    {
        $image = asset(Storage::url('assets/cover-image.png'));
        // dd($image);
        $apiKey = Config::get('constant.gemini_keys.key');
    //    dd($apiKey);
        // Your requirement variables
        $design = $request->input('design'); // color, placement, font, etc.
        $slideText = $request->input('slide_texts');
        $concept = $request->input('image_concept');
 
        // 1. Construct the reasoning-based prompt
        $prompt = "Create an Instagram graphic. Concept: {$concept}. "
                . "Overlay the text '{$slideText}' exactly. "
                . "Design requirements: Color {$design['overlay_color']}, "
                . "Placement {$design['text_placement']}, "
                . "Font {$design['font_family']}, Weight {$design['font_weight']}, Size {$design['font_size']}. "
                . "Render the text with high-fidelity professional typography.";

        try{
            // 2. Execute the Request
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image-preview:generateContent?key={$apiKey}", [
                    "contents" => [["parts" => [["text" => $prompt]]]],
                    "generationConfig" => [
                        "imageConfig" => [
                            "aspectRatio" => "1:1", // Use '9:16' for Stories
                            "imageSize" => "HD"   // Options: 'SD', 'HD', '2K', '4K'
                        ]
                    ]
                ]);
                
                if ($response->failed()) {
                    // This will show you the ACTUAL reason (e.g., "Invalid model name" or "Safety block")
                    dd($apiKey,$response->json()); 
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

                return response()->json([
                    'status' => 'success',
                    'image_url' => $dataUrl
                ]);
            }
            // return response()->json(['error' => 'Generation Failed'], 500);
        } catch (\Exception $e) {
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
        Based on the chapter content '{$chapter->content}', generate a high-quality post social media content for Instagram and TikTok posts.
        You MUST respond ONLY in JSON format Do not include any introductory text, markdown formatting (like ```json), or explanations,with the following keys:
        'caption': A catchy caption with emojis.
        'hashtags': A string of 10-15 trending hashtags as comma separated values.
        'script': A short video script.
        'title': A scroll-stopping headline.";
        
        return $systemInstruction;
    }
    

}
