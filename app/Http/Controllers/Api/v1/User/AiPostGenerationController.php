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
            'model'   => 'required|string', // e.g., 'gpt-4', 'claude-3'
            'prompt'  => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }
        $chapter = Chapter::find($request->chapter);
        $modelChoice = $request->model;
        $userPrompt = $request->prompt;

        // Final prompt jo AI ko jayega
        // $finalPrompt = "Chapter: {$chapter->content}\n\nTask: {$userPrompt}";
        $finalPrompt = "Task: {$userPrompt}";

        if (str_contains($modelChoice, 'gpt')) {
            return $this->generateWithOpenAI($modelChoice, $finalPrompt,$chapter);
        } elseif (str_contains($modelChoice, 'claude')) {
            return $this->generateWithClaude($modelChoice, $finalPrompt,$chapter);
        }

        return $this->sendError('Invalid Model Selected', [], 400);
    }

    private function generateWithOpenAI($model, $prompt,$chapter)
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

             // --- NEW: Image Generation Step ---
            // Caption ka use karke ek visual prompt banayein
            $imagePrompt = "A high-quality social media graphic about: " . $structuredData['title'];            
            $imageUrl = $this->generateAIImage($imagePrompt);

            return $this->sendResponse([
                'caption' => $structuredData['caption'],
                'hashtags' => $structuredData['hashtags'],
                'script' => $structuredData['script'],
                'title' => $structuredData['title'],
                'model' => $model,
                'chapter' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $chapter->chapter),
                'chapter_title' => $chapter->chapter_title,
                'chapter_id' => $chapter->id,
                'ai_prompt' => $prompt,
                'generated_image' => $imageUrl,
            ], 'Content generated successfully', 200);
        } catch (\Exception $e) {
            return $this->sendError('Error generating content', ['error' => $e->getMessage()], 500);
        }
    }


    private function generateWithClaude($model, $prompt, $chapter)
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

            // --- NEW: Image Generation Step ---
            // Caption ka use karke ek visual prompt banayein
            $imagePrompt = "Create a professional social media graphic for: " . $structuredData['title'] . ". Style: Clean, modern, related to " . $chapter->chapter_title;
            
            $imageUrl = $this->generateAIImage($imagePrompt);

            // 3. Send successful response to React
            return $this->sendResponse([
                'caption' => $structuredData['caption'] ?? '',
                'hashtags' => $structuredData['hashtags'] ?? '',
                'script' => $structuredData['script'] ?? '',
                'title' => $structuredData['title'] ?? '',
                'model' => $model,
                'chapter' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $chapter->chapter),
                'chapter_title' => $chapter->chapter_title,
                'chapter_id' => $chapter->id,
                'ai_prompt' => $prompt,
                'generated_image' => $imageUrl,
            ], 'Content generated successfully', 200);

        } catch (\Exception $e) {
            return $this->sendError('Error generating content', ['error' => $e->getMessage()], 500);
        }
    }

    private function generateAIImage($prompt)
    {
        $response = OpenAI::images()->create([
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024',
            'quality' => 'standard',
        ]);

        return $response->data[0]->url; // Temporary URL from OpenAI
    }
}
