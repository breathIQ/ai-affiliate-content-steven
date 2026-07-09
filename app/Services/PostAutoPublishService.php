<?php

namespace App\Services;

use App\Jobs\PublishPostToSocialMedia;
use App\Mail\GenerationFailedMail;
use App\Models\Chapter;
use App\Models\Post;
use App\Models\PostPlatform;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Bridges HeyGen/Grok video generation directly to publishing, for users
 * who choose "publish automatically" or "schedule" instead of reviewing the
 * finished render first. Platform selection happens up front
 * (preparePendingPublish, called from HeygenController/GrokVideoController
 * generate()), then whichever poller (HeygenGenerationPoller /
 * GrokVideoGenerationPoller) detects the render finished calls
 * finalizeReadyPost/handleGenerationFailed once media exists.
 */
class PostAutoPublishService
{
    public function __construct(protected PostMediaService $postMedia) {}

    /**
     * Creates the Post up front for a "publish now"/"schedule" generation -
     * there's no draft to attach to yet since the review step is being
     * skipped entirely. Mirrors the chapter lookup + field mapping
     * PostController::store() uses, minus platforms (preparePendingPublish
     * handles those) and media (attached by finalizeReadyPost once the
     * render finishes). Shared by HeygenController, GrokVideoController
     * (create mode), and AiPostGenerationController.
     */
    public function createPendingPost($user, int $chapterId, string $caption, string $aiModel, string $mediaAssets = 'single', ?string $hashtags = null): Post
    {
        $chapterName = Chapter::join('book_chapters', 'chapters.chapter', '=', 'book_chapters.chapter')
            ->where('chapters.id', $chapterId)
            ->select('chapters.id', 'chapters.chapter', 'book_chapters.chapter_title as chapter_title')
            ->first();

        if (! $chapterName) {
            $chapterName = Chapter::where('id', $chapterId)->first();
        }

        return Post::create([
            'user_id' => $user->id,
            'chapter_id' => $chapterId,
            'caption' => $caption,
            'media_assets' => $mediaAssets,
            'status' => 'processing', // immediately overwritten by preparePendingPublish()
            'ai_model' => $aiModel,
            'scheduled_at' => null,
            'published_at' => null,
            'hastag' => $hashtags,
            'affiliate_url' => $user->amazon_link ?: config('services.amazon.book_url'),
            'chapter_name' => $chapterName->chapter,
            'chapter_title' => $chapterName->chapter_title,
        ]);
    }

    /**
     * Records platform/TikTok settings decided at generation kickoff,
     * before any media exists yet. Same PostPlatform shape as
     * PostController::publish()'s loop, just created earlier - publish-due
     * and PublishPostToSocialMedia don't care when the rows were created.
     */
    public function preparePendingPublish(Post $post, array $settings): void
    {
        foreach ($settings['platforms'] as $platform) {
            $tiktokPayload = $platform === 'tiktok' ? json_encode([
                'content_disclose' => $settings['content_disclose'] ?? null,
                'brand_organic' => $settings['brand_organic'] ?? null,
                'branded_content' => $settings['branded_content'] ?? null,
                'allow_comment' => $settings['allow_comment'] ?? null,
                'allow_duet' => $settings['allow_duet'] ?? null,
                'allow_stitch' => $settings['allow_stitch'] ?? null,
                'privacy_level' => $settings['privacy_level'] ?? null,
            ]) : null;

            PostPlatform::create([
                'post_id' => $post->id,
                'platform' => $platform,
                'status' => 'processing',
                'tiktok_payload' => $tiktokPayload,
            ]);
        }

        $post->update([
            'status' => 'pending_render',
            'scheduled_at' => $settings['scheduled_at'] ?? null,
        ]);
    }

    /**
     * Called once a HeyGen/Grok render finishes for a post that was set up
     * via preparePendingPublish(). Guarded on the post still being
     * pending_render so a duplicate poller tick (or an overlapping frontend
     * poll, for a caller that also happens to hit this) can never
     * double-attach media or double-dispatch a publish. Accepts an array of
     * URLs for image carousels (AiPostGenerationController), where several
     * slides finish at once.
     */
    public function finalizeReadyPost(Post $post, string|array $mediaUrls): void
    {
        $post->refresh();

        if ($post->status !== 'pending_render') {
            return;
        }

        foreach ((array) $mediaUrls as $index => $mediaUrl) {
            $this->postMedia->attachFromUrl($post, $mediaUrl, $index + 1);
        }

        if ($post->scheduled_at) {
            $post->update(['status' => 'scheduled']);
            return;
        }

        $post->update(['status' => 'processing']);

        PublishPostToSocialMedia::dispatch($post);
    }

    public function handleGenerationFailed(Post $post, string $reason): void
    {
        $post->refresh();

        if ($post->status !== 'pending_render') {
            return;
        }

        $post->update(['status' => 'failed']);

        try {
            Mail::to($post->user->email)->send(new GenerationFailedMail($post, $reason));
        } catch (\Throwable $e) {
            Log::error('Failed to send generation-failed email', ['post_id' => $post->id, 'error' => $e->getMessage()]);
        }
    }
}
