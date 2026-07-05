<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Api\v1\ResponseController;
use App\Models\GrokVideoGeneration;
use App\Models\Post;
use App\Services\CreditService;
use App\Services\GrokVideoGenerationPoller;
use App\Services\GrokVideoPricingService;
use App\Services\GrokVideoService;
use App\Services\PostAutoPublishService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class GrokVideoController extends ResponseController
{
    public function __construct(
        protected GrokVideoService $grok,
        protected CreditService $credits,
        protected GrokVideoPricingService $pricing,
        protected PostAutoPublishService $autoPublish,
    ) {}

    /**
     * Turn an already-generated post image into a short video via Grok's
     * image-to-video mode. Duration is fixed to 6 or 10 seconds (chosen
     * upfront), so - unlike HeyGen - the exact cost is known and charged
     * before the request is sent, no reconciliation needed afterward.
     */
    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image_url' => 'required|url',
            'duration_seconds' => 'required|integer|in:6,10',
            'resolution' => 'nullable|in:480p,720p',
            'prompt' => 'nullable|string|max:2000',
            'audio_mode' => 'nullable|in:auto,voice,music,silent',
            'post_id' => 'nullable|exists:posts,id',

            // Publish-without-review: with a post_id the existing post is
            // published once the render finishes; without one (create mode -
            // the user uploaded an image that isn't a post yet) a Post is
            // created up front from chapter_id + caption, exactly like the
            // HeyGen flow - see PostAutoPublishService for the full flow.
            'publish_action' => 'nullable|in:review,publish_now,schedule',
            'chapter_id' => 'nullable|exists:chapters,id',
            'caption' => 'nullable|string',
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
        ]);

        if ($request->input('publish_action', 'review') !== 'review' && ! $request->filled('post_id')
            && ! ($request->filled('chapter_id') && $request->filled('caption'))) {
            return $this->sendValidationError(['post_id' => ['Either post_id, or chapter_id + caption, is required when publish_action is not review.']]);
        }

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }

        $user = Auth::user();
        $durationSeconds = (int) $request->duration_seconds;
        $resolution = $request->input('resolution') ?: config('services.fal.grok_video_resolution');
        $cost = $this->pricing->creditsForDuration($durationSeconds, $resolution);

        if (! $this->credits->hasSufficientBalance($user, $cost)) {
            return $this->sendError(
                "Not enough credits. This video costs {$cost} credits, you have {$user->credits_balance}.",
                ['credits_required' => $cost, 'credits_balance' => $user->credits_balance],
                422
            );
        }

        $deducted = $this->credits->deduct($user, $cost, "Grok image-to-video generation ({$durationSeconds}s)");

        if (! $deducted) {
            return $this->sendError('Not enough credits.', [], 422);
        }

        try {
            $result = $this->grok->generateFromImage($request->image_url, $durationSeconds, $request->prompt, $resolution, $request->audio_mode);

            $generation = GrokVideoGeneration::create([
                'user_id' => $user->id,
                'post_id' => $request->post_id,
                'grok_request_id' => $result['request_id'],
                'source_image_url' => $request->image_url,
                'prompt' => $request->prompt,
                'duration_seconds' => $durationSeconds,
                'status' => 'pending',
                'credits_charged' => $cost,
                'request_payload' => array_merge($result['request_payload'], [
                    'status_url' => $result['status_url'],
                    'response_url' => $result['response_url'],
                ]),
            ]);

            $publishAction = $request->input('publish_action', 'review');

            if ($publishAction !== 'review') {
                if ($request->filled('post_id')) {
                    $post = Post::where('id', $request->post_id)->where('user_id', $user->id)->firstOrFail();
                } else {
                    // Create mode: the source image was uploaded directly and
                    // never saved as a post, so create one now for the
                    // finished video to land in.
                    $post = $this->autoPublish->createPendingPost($user, (int) $request->chapter_id, $request->caption, 'grok');
                    $generation->post_id = $post->id;
                    $generation->save();
                }

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
            }

            return $this->sendResponse([
                'generation_id' => $generation->id,
                'status' => $generation->status,
                'publish_action' => $publishAction,
                'post_id' => $generation->post_id,
                'credits_balance' => $user->fresh()->credits_balance,
            ], 'Video generation started', 201);
        } catch (\Throwable $e) {
            $this->credits->refund($user, $cost, 'Refund: Grok request failed before generation started');

            Log::error('Grok video generation failed to start', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            return $this->sendError('Video generation failed to start', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Poll a generation's status. Refunds credits automatically the first
     * time a generation is observed to have failed. The actual logic lives
     * in GrokVideoGenerationPoller so the same polling (and its completion
     * hook into PostAutoPublishService) also runs from the
     * grok:poll-pending-publish console command, without a browser.
     */
    public function status(Request $request, int $id, GrokVideoGenerationPoller $poller)
    {
        $generation = GrokVideoGeneration::where('user_id', Auth::id())->findOrFail($id);

        try {
            $poller->advance($generation);

            return $this->sendResponse($generation, 'Generation status retrieved successfully', 200);
        } catch (\Throwable $e) {
            Log::error('Grok video status check failed', ['error' => $e->getMessage(), 'generation_id' => $generation->id]);
            return $this->sendError('Could not check generation status', ['error' => $e->getMessage()], 500);
        }
    }
}
