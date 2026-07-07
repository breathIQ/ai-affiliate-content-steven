<?php

namespace App\Jobs;

use App\Models\HeygenGeneration;
use App\Models\HeygenVoiceClone;
use App\Services\ChatterboxService;
use App\Services\CreditService;
use App\Services\HeygenService;
use App\Services\PostAutoPublishService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the open-source "talking-head" voice pipeline off the request path
 * (Chatterbox synthesis takes ~30-60s - too long to block an HTTP request on
 * shared FPM): synthesise the script in the user's cloned voice, upload it to
 * HeyGen, and kick off a talking_photo render. On success it just sets
 * heygen_video_id and the existing HeygenGenerationPoller finishes the render
 * (outro + publish). This job owns the failure/refund path, since the poller
 * only sees the generation once a video_id exists.
 *
 * Credits are already deducted by the controller before dispatch, so a failure
 * here refunds them.
 */
class ProcessAudioVoiceGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 300;

    public function __construct(public int $generationId) {}

    public function handle(
        HeygenService $heygen,
        ChatterboxService $chatterbox,
        CreditService $credits,
        PostAutoPublishService $autoPublish,
    ): void {
        $generation = HeygenGeneration::find($this->generationId);

        if (! $generation || $generation->heygen_video_id || in_array($generation->status, ['completed', 'failed'], true)) {
            return;
        }

        $payload = $generation->request_payload ?? [];
        $voice = HeygenVoiceClone::find($payload['voice_clone_id'] ?? null);
        $talkingPhotoId = $payload['avatar_id'] ?? null;
        $orientation = $payload['orientation'] ?? 'portrait';

        try {
            if (! $voice || ! $voice->reference_audio_url) {
                throw new \RuntimeException('Voice clone reference audio is missing.');
            }
            if (! $talkingPhotoId) {
                throw new \RuntimeException('No photo avatar was provided for talking-head generation.');
            }

            [$audioBytes, $mime] = $chatterbox->synthesize($generation->prompt, $voice->reference_audio_url);
            $asset = $heygen->uploadAudioAsset($audioBytes, $mime);

            $dimension = $orientation === 'landscape'
                ? ['width' => 1280, 'height' => 720]
                : ['width' => 720, 'height' => 1280];

            $videoId = $heygen->generateAudioDrivenVideo($talkingPhotoId, $asset['asset_id'], $dimension);

            $generation->heygen_video_id = $videoId;
            $generation->status = 'processing';
            $generation->save();
        } catch (\Throwable $e) {
            Log::error('Audio-voice generation failed', ['generation_id' => $generation->id, 'error' => $e->getMessage()]);
            $this->fail($generation, $credits, $autoPublish, $e->getMessage());
        }
    }

    /**
     * Laravel's job-failure hook (uncaught throwable / timeout). Mirrors the
     * in-handle failure path so a hard failure still refunds and notifies.
     */
    public function failed(\Throwable $e): void
    {
        $generation = HeygenGeneration::find($this->generationId);
        if ($generation && $generation->status !== 'failed') {
            $this->fail(
                $generation,
                app(CreditService::class),
                app(PostAutoPublishService::class),
                $e->getMessage(),
            );
        }
    }

    protected function fail(HeygenGeneration $generation, CreditService $credits, PostAutoPublishService $autoPublish, string $reason): void
    {
        $generation->status = 'failed';
        $generation->error_message = $reason;
        $generation->save();

        $credits->refund($generation->user, (int) $generation->credits_charged, 'Refund: talking-head voice generation failed', $generation);

        if ($generation->post_id) {
            $autoPublish->handleGenerationFailed($generation->post, $reason);
        }
    }
}
