<?php

namespace App\Services;

use App\Models\GrokVideoGeneration;

/**
 * The Grok/fal.ai-polling logic, extracted from GrokVideoController::status()
 * so it can run both from an authenticated HTTP request (frontend polling,
 * review flow) and from the grok:poll-pending-publish console command
 * (auto-publish/schedule flow, no browser involved).
 */
class GrokVideoGenerationPoller
{
    public function __construct(
        protected GrokVideoService $grok,
        protected CreditService $credits,
        protected PostAutoPublishService $autoPublish,
    ) {}

    public function advance(GrokVideoGeneration $generation): GrokVideoGeneration
    {
        if (in_array($generation->status, ['completed', 'failed'], true)) {
            return $generation;
        }

        $result = $this->grok->getStatus(
            $generation->request_payload['status_url'],
            $generation->request_payload['response_url']
        );
        $generation->status = $result['status'];

        if ($result['status'] === 'completed') {
            $generation->video_url = $result['video_url'];
        }

        if ($result['status'] === 'failed') {
            $generation->error_message = is_array($result['error'])
                ? json_encode($result['error'])
                : 'fal.ai reported a failure with no message.';

            $this->credits->refund(
                $generation->user,
                $generation->credits_charged,
                'Refund: Grok video generation failed',
                $generation
            );
        }

        $generation->save();

        if ($generation->status === 'completed' && $generation->post_id) {
            $this->autoPublish->finalizeReadyPost($generation->post, $generation->video_url);
        }

        if ($generation->status === 'failed' && $generation->post_id) {
            $this->autoPublish->handleGenerationFailed($generation->post, $generation->error_message ?? 'Grok video generation failed');
        }

        return $generation;
    }
}
