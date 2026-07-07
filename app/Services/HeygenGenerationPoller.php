<?php

namespace App\Services;

use App\Models\HeygenGeneration;
use Illuminate\Support\Facades\Log;

/**
 * The HeyGen-polling logic, extracted from HeygenController::status() so it
 * can run both from an authenticated HTTP request (frontend polling, review
 * flow) and from the heygen:poll-pending-publish console command
 * (auto-publish/schedule flow, no browser involved).
 */
class HeygenGenerationPoller
{
    public function __construct(
        protected HeygenService $heygen,
        protected CreditService $credits,
        protected HeygenPricingService $pricing,
        protected VideoOutroService $outro,
        protected PostAutoPublishService $autoPublish,
    ) {}

    public function advance(HeygenGeneration $generation): HeygenGeneration
    {
        if (in_array($generation->status, ['completed', 'failed'], true)) {
            return $generation;
        }

        $isAudioMode = $generation->generation_mode === 'audio';

        // Audio mode: the render is kicked off by ProcessAudioVoiceGeneration
        // (voice synthesis + /v2/video/generate), which sets heygen_video_id
        // when it succeeds. Until then there's nothing to poll - the job also
        // owns the failure/refund path if synthesis or kickoff fails.
        if ($isAudioMode && ! $generation->heygen_video_id) {
            return $generation;
        }

        // Stage 1 (agent mode only): do we have a video_id yet? Poll the agent
        // session until we do. Audio-mode generations are created with a
        // video_id already set (the /v2/video/generate call returns it
        // synchronously) and have no session, so they skip straight to Stage 2.
        if (! $isAudioMode && ! $generation->heygen_video_id) {
            $session = $this->heygen->getSessionStatus($generation->heygen_session_id);
            $generation->status = $session['status'];

            if ($session['video_id']) {
                $generation->heygen_video_id = $session['video_id'];
            }

            if ($session['status'] === 'failed') {
                $this->refundFailedGeneration($generation, 'HeyGen agent session failed before a video was produced');
            }

            $generation->save();

            if ($generation->status === 'failed') {
                $this->notifyPostOfFailure($generation, 'HeyGen agent session failed before a video was produced');
            }

            return $generation;
        }

        // Stage 2: we have a video_id, poll its render status. Audio-mode
        // videos come from /v2/video/generate and report through a different
        // status endpoint, but the returned shape is identical from here on.
        $result = $isAudioMode
            ? $this->heygen->getAudioVideoStatus($generation->heygen_video_id)
            : $this->heygen->getVideoStatus($generation->heygen_video_id);
        $generation->status = $result['status'];

        if ($result['status'] === 'completed') {
            // Billing must reflect what HeyGen actually rendered, before
            // any outro is appended - the outro's seconds aren't billed.
            // Audio mode skips reconciliation: its upfront charge bundles a
            // fixed Chatterbox synthesis cost that duration-based
            // reconciliation would wrongly refund away.
            if ($result['duration']) {
                $generation->duration_seconds = $result['duration'];
                if (! $isAudioMode) {
                    $this->reconcileActualCost($generation, (float) $result['duration']);
                }
            }

            $orientation = $generation->request_payload['orientation'] ?? 'portrait';
            $finalUrl = $this->outro->appendCoverOutro($result['video_url'], $orientation);
            $generation->video_url = $finalUrl ?? $result['video_url'];
        }

        if ($result['status'] === 'failed') {
            $generation->error_message = $result['error']['message'] ?? 'HeyGen reported a failure with no message.';
            $this->refundFailedGeneration($generation, 'HeyGen video render failed');
        }

        $generation->save();

        if ($generation->status === 'completed' && $generation->post_id) {
            $this->autoPublish->finalizeReadyPost($generation->post, $generation->video_url);
        }

        if ($generation->status === 'failed') {
            $this->notifyPostOfFailure($generation, $generation->error_message ?? 'HeyGen video render failed');
        }

        return $generation;
    }

    protected function notifyPostOfFailure(HeygenGeneration $generation, string $reason): void
    {
        if ($generation->post_id) {
            $this->autoPublish->handleGenerationFailed($generation->post, $reason);
        }
    }

    protected function refundFailedGeneration(HeygenGeneration $generation, string $reason): void
    {
        $this->credits->refund($generation->user, $generation->credits_charged, "Refund: {$reason}", $generation);
    }

    /**
     * Reconcile the upfront estimated hold against the real cost now that
     * the actual video duration is known. Refunds the difference if the
     * hold was more than needed; if the video ran longer than the estimate
     * covered, attempts to collect the shortfall from the user's remaining
     * balance (best-effort - we don't fail or hide the finished video over
     * an under-collection, we just log it for follow-up).
     */
    protected function reconcileActualCost(HeygenGeneration $generation, float $durationSeconds): void
    {
        $actualCost = $this->pricing->creditsForDuration($durationSeconds);
        $held = (int) $generation->credits_charged;

        if ($actualCost < $held) {
            $refund = $held - $actualCost;
            $this->credits->refund($generation->user, $refund, 'Refund: actual video shorter than estimated hold', $generation);
            $generation->credits_charged = $actualCost;
            return;
        }

        if ($actualCost > $held) {
            $shortfall = $actualCost - $held;

            if ($this->credits->deduct($generation->user, $shortfall, 'HeyGen video generation (duration overage)', $generation)) {
                $generation->credits_charged = $actualCost;
            } else {
                Log::warning('HeyGen generation ran longer than the estimated hold and the user lacked balance to cover it', [
                    'generation_id' => $generation->id,
                    'user_id' => $generation->user_id,
                    'held' => $held,
                    'actual_cost' => $actualCost,
                    'duration_seconds' => $durationSeconds,
                ]);
            }
        }
    }
}
