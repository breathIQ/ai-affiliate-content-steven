<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Open-source voice synthesis via Chatterbox HD (Resemble AI, MIT-licensed
 * model) on fal.ai's queue API. Given a script and a reference audio URL of
 * the target voice, returns the synthesised speech as raw bytes for the
 * caller to hand to HeyGen as an audio-driven avatar track.
 *
 * Proven end-to-end 2026-07-07 (server-scripts/poc_chatterbox_voice_clone.php).
 * fal returns a WAV; callers uploading it to HeyGen must send
 * Content-Type: audio/x-wav (see HeygenService::uploadAudioAsset()).
 */
class ChatterboxService
{
    protected ?string $apiKey;
    protected string $baseUrl;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.fal.api_key');
        $this->baseUrl = rtrim(config('services.fal.base_url'), '/');
        $this->model = config('services.fal.chatterbox_model');
    }

    /**
     * Synthesise $text in the voice sampled at $referenceAudioUrl. Returns
     * [bytes, mime]. Polls the fal queue until the job completes.
     */
    public function synthesize(string $text, string $referenceAudioUrl): array
    {
        if (! $this->apiKey) {
            throw new \RuntimeException('fal.ai API key is not configured.');
        }

        $submit = Http::withHeaders([
            'Authorization' => "Key {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(60)->post("{$this->baseUrl}/{$this->model}", [
            'text' => $text,
            'audio_url' => $referenceAudioUrl,
            'high_quality_audio' => true,
        ]);

        if ($submit->failed()) {
            Log::error('Chatterbox submit failed', ['status' => $submit->status(), 'body' => $submit->body()]);
            throw new \RuntimeException('Voice synthesis request failed: '.$submit->body());
        }

        $statusUrl = $submit->json('status_url');
        $responseUrl = $submit->json('response_url');

        if (! $statusUrl || ! $responseUrl) {
            throw new \RuntimeException('fal did not return queue URLs: '.$submit->body());
        }

        // Poll the queue - Chatterbox typically finishes in well under a
        // minute; cap at ~3.5 minutes so a stuck job can't hang the request.
        $audioUrl = null;
        for ($i = 0; $i < 42; $i++) {
            sleep(5);
            $status = Http::withHeaders(['Authorization' => "Key {$this->apiKey}"])
                ->timeout(30)->get($statusUrl);
            $state = $status->json('status');

            if ($state === 'COMPLETED') {
                $result = Http::withHeaders(['Authorization' => "Key {$this->apiKey}"])
                    ->timeout(30)->get($responseUrl);
                $audioUrl = $result->json('audio.url');
                break;
            }

            if (in_array($state, ['FAILED', 'ERROR'], true)) {
                Log::error('Chatterbox job failed', ['body' => $status->body()]);
                throw new \RuntimeException('Voice synthesis failed.');
            }
        }

        if (! $audioUrl) {
            throw new \RuntimeException('Voice synthesis timed out.');
        }

        $audio = Http::timeout(60)->get($audioUrl);
        if ($audio->failed()) {
            throw new \RuntimeException('Could not download synthesised audio.');
        }

        // fal serves Chatterbox output as WAV; HeyGen sniffs the file and
        // rejects a mismatched Content-Type, requiring audio/x-wav for it.
        $mime = str_ends_with(strtolower(parse_url($audioUrl, PHP_URL_PATH) ?? ''), '.wav')
            ? 'audio/x-wav'
            : 'audio/mpeg';

        return [$audio->body(), $mime];
    }
}
