<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GrokVideoService
{
    // Nullable rather than a plain string: a non-nullable type here would
    // fatal the whole GrokVideoController (via constructor injection) if
    // FAL_KEY isn't set yet, instead of failing gracefully only when a
    // video generation is actually attempted.
    protected ?string $apiKey;
    protected string $baseUrl;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.fal.api_key');
        $this->baseUrl = rtrim(config('services.fal.base_url'), '/');
        $this->model = config('services.fal.grok_video_model');
    }

    protected function headers(): array
    {
        return [
            'Authorization' => "Key {$this->apiKey}",
            'Content-Type' => 'application/json',
        ];
    }

    // fal.ai's Grok Imagine Video API has no dedicated audio parameter -
    // audio is generated automatically as part of the video, driven by
    // prompt context. This is a best-effort steer appended to the prompt,
    // not a guaranteed hard setting.
    protected const AUDIO_MODE_INSTRUCTIONS = [
        'voice' => 'Include natural spoken dialogue or narration from the person/subject in the video.',
        'music' => 'Add soft background music, with no spoken dialogue or voice.',
        'silent' => 'Completely silent - no dialogue, no music, no sound effects.',
    ];

    /**
     * Submit an image-to-video job to fal.ai's queue. Returns a request_id
     * to poll with getStatus() - fal.ai's queue is async, unlike a
     * synchronous API, so nothing is available yet on return.
     */
    public function generateFromImage(string $imageUrl, int $durationSeconds, ?string $prompt = null, ?string $resolution = null, ?string $audioMode = null): array
    {
        $finalPrompt = $prompt ?: 'Bring this image to life with subtle, natural motion. Keep the composition, text, and subject matter unchanged.';

        if ($audioMode && isset(self::AUDIO_MODE_INSTRUCTIONS[$audioMode])) {
            $finalPrompt .= ' '.self::AUDIO_MODE_INSTRUCTIONS[$audioMode];
        }

        $payload = [
            'prompt' => $finalPrompt,
            'image_url' => $imageUrl,
            'duration' => $durationSeconds,
            'resolution' => $resolution ?: config('services.fal.grok_video_resolution'),
        ];

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->post("{$this->baseUrl}/{$this->model}", $payload);

        if ($response->failed()) {
            Log::error('fal.ai Grok image-to-video submission failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('fal.ai video request failed: '.$response->body());
        }

        $data = $response->json();
        $requestId = $data['request_id'] ?? null;
        $statusUrl = $data['status_url'] ?? null;
        $responseUrl = $data['response_url'] ?? null;

        if (! $requestId || ! $statusUrl || ! $responseUrl) {
            throw new \RuntimeException('fal.ai response did not include request_id/status_url/response_url: '.$response->body());
        }

        return [
            'request_id' => $requestId,
            'status_url' => $statusUrl,
            'response_url' => $responseUrl,
            'raw' => $data,
            'request_payload' => $payload,
        ];
    }

    /**
     * Poll a generation's status using the exact status_url/response_url
     * fal.ai returned at submission time - these must NOT be reconstructed
     * from the model id, since the queue's request namespace can differ
     * from the submission model path (e.g. submitting to
     * xai/grok-imagine-video/image-to-video returns status/response URLs
     * under xai/grok-imagine-video, dropping the last path segment).
     * fal.ai's queue reports IN_QUEUE, IN_PROGRESS, or COMPLETED; once
     * COMPLETED, the final video URL is fetched from response_url.
     */
    public function getStatus(string $statusUrl, string $responseUrl): array
    {
        $statusResponse = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get($statusUrl);

        if ($statusResponse->failed()) {
            Log::error('fal.ai Grok video status check failed', [
                'status_url' => $statusUrl,
                'status' => $statusResponse->status(),
                'body' => $statusResponse->body(),
            ]);

            throw new \RuntimeException('fal.ai video status check failed: '.$statusResponse->body());
        }

        $statusData = $statusResponse->json();
        $rawStatus = $statusData['status'] ?? 'IN_QUEUE';

        if ($rawStatus !== 'COMPLETED') {
            return [
                'status' => in_array($rawStatus, ['IN_QUEUE', 'IN_PROGRESS'], true) ? 'pending' : 'failed',
                'video_url' => null,
                'error' => $rawStatus !== 'IN_QUEUE' && $rawStatus !== 'IN_PROGRESS' ? $statusData : null,
                'raw' => $statusData,
            ];
        }

        $resultResponse = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get($responseUrl);

        if ($resultResponse->failed()) {
            Log::error('fal.ai Grok video result fetch failed', [
                'response_url' => $responseUrl,
                'status' => $resultResponse->status(),
                'body' => $resultResponse->body(),
            ]);

            throw new \RuntimeException('fal.ai video result fetch failed: '.$resultResponse->body());
        }

        $resultData = $resultResponse->json();
        $videoUrl = $resultData['video']['url'] ?? null;

        if (! $videoUrl) {
            return [
                'status' => 'failed',
                'video_url' => null,
                'error' => $resultData,
                'raw' => $resultData,
            ];
        }

        return [
            'status' => 'completed',
            'video_url' => $videoUrl,
            'error' => null,
            'raw' => $resultData,
        ];
    }
}
