<?php

namespace App\Services;

use App\Models\AutomationStep;
use App\Models\Campaign;
use App\Models\Chapter;
use App\Models\GrokVideoGeneration;
use App\Models\HeygenGeneration;
use App\Models\User;
use App\Support\CampaignPromptBuilder;
use App\Support\CoreThesis;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Executes one automation-campaign step headlessly: generates the content and
 * hands it to the existing publish pipeline WITHOUT review. Reuses the same
 * services the interactive flows use - AiTextService/AiImageService for
 * content, HeygenService/GrokVideoService kickoffs finished by their
 * per-minute pollers, and PostAutoPublishService + PublishPostToSocialMedia
 * for publishing.
 *
 * Product promos still honor the compliance lint: a red-flag post is left as a
 * review-queue draft (step status "review") rather than publishing unchecked
 * health claims - the one exception to "no review", and a deliberate one.
 */
class AutomationStepRunner
{
    public function __construct(
        protected AiTextService $text,
        protected AiImageService $image,
        protected PostAutoPublishService $autoPublish,
        protected CreditService $credits,
        protected HeygenService $heygen,
        protected HeygenPricingService $heygenPricing,
        protected GrokVideoService $grok,
        protected GrokVideoPricingService $grokPricing,
        protected ImageGenerationPricingService $imagePricing,
        protected ClaimLintService $lintService,
        protected CampaignPromptBuilder $prompts,
        protected CampaignPostAssembler $assembler,
    ) {}

    /** Run one due step. Never throws - failures are recorded on the step. */
    public function run(AutomationStep $step): void
    {
        $step->update(['status' => 'running', 'executed_at' => now()]);
        try {
            $user = $step->campaign->user;
            // Some downstream helpers (image prompt) read Auth::user(); the
            // scheduler runs unauthenticated, so bind the campaign owner.
            Auth::setUser($user);

            match ($step->content_type) {
                'carousel_images' => $this->carouselImages($step, $user),
                'heygen_video'    => $this->heygenVideo($step, $user),
                'image_to_video'  => $this->imageToVideo($step, $user),
                'product_promo'   => $this->productPromo($step, $user),
                default           => throw new \RuntimeException("Unknown content type: {$step->content_type}"),
            };
        } catch (\Throwable $e) {
            Log::error('Automation step failed', ['step' => $step->id, 'error' => $e->getMessage()]);
            $step->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 900)]);
        }
    }

    // ---- content types ------------------------------------------------------

    /** Day-1 style: N educational carousel slides about a chapter, auto-published. */
    protected function carouselImages(AutomationStep $step, User $user): void
    {
        $p = $step->params ?? [];
        $slides = max(1, min(4, (int) ($p['slides'] ?? 3)));
        $engine = $p['image_model'] ?? 'openai';
        $model  = $p['model'] ?? 'claude';
        $chapter = $this->chapter($step->chapter_id);

        $hold = $this->imagePricing->estimatedHoldCredits($engine, $slides);
        if (! $this->credits->deduct($user, $hold, 'Automation: carousel generation (estimated hold)', $step)) {
            throw new \RuntimeException('Insufficient credits for carousel generation.');
        }

        try {
            $text = $this->bookText($chapter, $model, $p['prompt'] ?? null);

            $urls = [];
            for ($i = 0; $i < $slides; $i++) {
                $result = $this->image->generate($engine, $this->bookSlidePrompt($chapter, $text['image_text'] ?? $text['caption'] ?? ''));
                if (($result['success'] ?? false) && ! empty($result['image_url'])) {
                    $urls[] = $result['image_url'];
                }
            }
            if (count($urls) === 0) {
                throw new \RuntimeException('All slide images failed to generate.');
            }

            $failed = $slides - count($urls);
            if ($failed > 0) {
                $this->credits->refund($user, $failed * $this->imagePricing->imageCostCredits($engine), 'Automation refund: failed slides', $step);
            }

            $post = $this->autoPublish->createPendingPost(
                $user, (int) $step->chapter_id, $this->caption($text), $this->text->providerLabel($model),
                count($urls) > 1 ? 'carousel' : 'single', $text['hashtags'] ?? null
            );
            $this->autoPublish->preparePendingPublish($post, ['platforms' => $step->campaign->platforms]);
            $this->autoPublish->finalizeReadyPost($post, $urls);
            $step->update(['status' => 'queued', 'post_id' => $post->id]);
        } catch (\Throwable $e) {
            $this->credits->refund($user, $hold, 'Automation refund: carousel failed', $step);
            throw $e;
        }
    }

    /** Day-2 style: a HeyGen avatar video whose script is written by the LLM. */
    protected function heygenVideo(AutomationStep $step, User $user): void
    {
        $p = $step->params ?? [];
        $duration = max(10, min(120, (int) ($p['duration_seconds'] ?? 60)));
        $model    = $p['model'] ?? 'claude';
        $defaults = $step->campaign->defaults ?? [];
        $avatarId = $p['avatar_id'] ?? ($defaults['avatar_id'] ?? null);
        $voiceId  = $p['voice_id'] ?? ($defaults['voice_id'] ?? null);
        if (! $avatarId) {
            throw new \RuntimeException('No HeyGen avatar configured for this step or campaign.');
        }

        $chapter = $this->chapter($step->chapter_id);
        $script  = $this->videoScript($chapter, $model, $duration, $p['prompt'] ?? null);
        // A short social caption for the video post (the script is the spoken
        // narration, not the caption). Cheap extra text call, not charged -
        // matches the interactive flow where drafting text is free.
        $meta = $this->bookText($chapter, $model, $p['prompt'] ?? null);

        $cost = $this->heygenPricing->creditsForDuration($duration);
        if (! $this->credits->deduct($user, $cost, 'Automation: HeyGen video (estimated hold)', $step)) {
            throw new \RuntimeException('Insufficient credits for HeyGen video.');
        }

        try {
            $affiliateUrl = $user->amazon_link ?: config('services.amazon.book_url');
            $result = $this->heygen->generateFromPrompt([
                'prompt' => $script,
                'duration_seconds' => $duration,
                'orientation' => $p['orientation'] ?? 'portrait',
                'avatar_id' => $avatarId,
                'voice_id' => $voiceId,
                'affiliate_url' => $affiliateUrl,
            ]);

            // Create the post first so the poller has somewhere to land the video.
            $post = $this->autoPublish->createPendingPost(
                $user, (int) $step->chapter_id, $this->caption($meta), 'HeyGen', 'single', $meta['hashtags'] ?? null
            );
            $this->autoPublish->preparePendingPublish($post, ['platforms' => $step->campaign->platforms]);

            HeygenGeneration::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'heygen_video_id' => $result['video_id'],
                'prompt' => $script,
                'status' => $result['status'],
                'generation_mode' => 'agent',
                'credits_charged' => $cost,
                'request_payload' => $result['request_payload'],
            ]);

            // heygen:poll-pending-publish finishes rendering + publishes.
            $step->update(['status' => 'queued', 'post_id' => $post->id]);
        } catch (\Throwable $e) {
            $this->credits->refund($user, $cost, 'Automation refund: HeyGen kickoff failed', $step);
            throw $e;
        }
    }

    /** Day-5 style: generate an image about a chapter, then Grok animates it. */
    protected function imageToVideo(AutomationStep $step, User $user): void
    {
        $p = $step->params ?? [];
        $engine   = $p['image_model'] ?? 'openai';
        $model    = $p['model'] ?? 'claude';
        $duration = in_array((int) ($p['duration_seconds'] ?? 6), [6, 10], true) ? (int) ($p['duration_seconds'] ?? 6) : 6;
        $resolution = $p['resolution'] ?? '720p';
        $chapter = $this->chapter($step->chapter_id);

        // 1) Generate the source image (charged like any image).
        $imgHold = $this->imagePricing->estimatedHoldCredits($engine, 1);
        if (! $this->credits->deduct($user, $imgHold, 'Automation: image-to-video source image', $step)) {
            throw new \RuntimeException('Insufficient credits for the source image.');
        }
        $text = $this->bookText($chapter, $model, $p['prompt'] ?? null);
        $img = $this->image->generate($engine, $this->bookSlidePrompt($chapter, $text['image_text'] ?? $text['caption'] ?? ''));
        if (! ($img['success'] ?? false) || empty($img['image_url'])) {
            $this->credits->refund($user, $imgHold, 'Automation refund: source image failed', $step);
            throw new \RuntimeException('Source image failed to generate.');
        }

        // 2) Kick off Grok image-to-video (poller finishes + publishes).
        $vidCost = $this->grokPricing->creditsForDuration($duration, $resolution);
        if (! $this->credits->deduct($user, $vidCost, 'Automation: Grok image-to-video', $step)) {
            throw new \RuntimeException('Insufficient credits for the video animation.');
        }

        try {
            $result = $this->grok->generateFromImage($img['image_url'], $duration, $p['prompt'] ?? null, $resolution, $p['audio_mode'] ?? 'auto');

            $post = $this->autoPublish->createPendingPost($user, (int) $step->chapter_id, $this->caption($text), 'grok', 'single', $text['hashtags'] ?? null);

            $generation = GrokVideoGeneration::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'grok_request_id' => $result['request_id'],
                'source_image_url' => $img['image_url'],
                'prompt' => $p['prompt'] ?? null,
                'duration_seconds' => $duration,
                'status' => 'pending',
                'credits_charged' => $vidCost,
                'request_payload' => array_merge($result['request_payload'], [
                    'status_url' => $result['status_url'],
                    'response_url' => $result['response_url'],
                ]),
            ]);

            $this->autoPublish->preparePendingPublish($post, ['platforms' => $step->campaign->platforms]);
            $step->update(['status' => 'queued', 'post_id' => $post->id]);
        } catch (\Throwable $e) {
            $this->credits->refund($user, $vidCost, 'Automation refund: Grok kickoff failed', $step);
            throw $e;
        }
    }

    /** Day-7 style: a promotional product post for a campaign (e.g. CO2 inhaler). */
    protected function productPromo(AutomationStep $step, User $user): void
    {
        $p = $step->params ?? [];
        $campaign = Campaign::where('slug', $step->campaign_slug)->first();
        if (! $campaign || ! $campaign->is_active) {
            throw new \RuntimeException("Campaign '{$step->campaign_slug}' is not available.");
        }

        $model  = $p['model'] ?? 'claude';
        $engine = $p['image_model'] ?? ((str_contains($model, 'gpt') || str_contains($model, 'claude')) ? 'openai' : 'gemini');
        $postType = $p['post_type'] ?? 'single';
        $slideCount = $postType === 'carousel' ? max(1, min(4, (int) ($p['slides'] ?? 1))) : 0;
        $design = $p['design'] ?? [];
        $theme  = $p['theme'] ?? null;

        $officialImage = $campaign->currentImageAsset();
        if ($campaign->requires_official_image && ! $officialImage) {
            throw new \RuntimeException('Official product image has not been uploaded for this campaign.');
        }
        if ($slideCount === 0 && ! $officialImage) {
            throw new \RuntimeException('Product post has no image (no slides and no official image).');
        }

        // 1) Copy - generated as JSON, then compliance-linted.
        $system = $this->prompts->system($campaign, $user, ['theme' => $theme, 'text_format' => $p['text_format'] ?? 'paragraph']);
        $userPrompt = $p['prompt'] ?? "Write a compelling, compliant promotional social post for this product. Theme: " . ($theme ?: 'general benefits') . '.';
        $approvedText = $this->text->generateJson($model, $system, $userPrompt);

        $lint = $this->lintService->lint($campaign, $approvedText, $user->affiliate_coupon);

        $hold = $slideCount > 0 ? $this->imagePricing->estimatedHoldCredits($engine, $slideCount) : 0;
        if ($hold > 0 && ! $this->credits->deduct($user, $hold, 'Automation: product promo slides (estimated hold)', $step)) {
            throw new \RuntimeException('Insufficient credits for product promo slides.');
        }

        try {
            $slideUrls = [];
            for ($i = 0; $i < $slideCount; $i++) {
                $prompt = $this->prompts->slideImagePrompt($campaign, $user, $design, $approvedText['image_text'] ?? '');
                $result = $this->image->generate($engine, $prompt, null);
                if (($result['success'] ?? false) && ! empty($result['image_url'])) {
                    $slideUrls[] = $result['image_url'];
                }
            }
            if ($hold > 0 && count($slideUrls) < $slideCount) {
                $refund = $this->imagePricing->imageCostCredits($engine) * ($slideCount - count($slideUrls));
                if ($refund > 0) {
                    $this->credits->refund($user, $refund, 'Automation refund: product slides that did not render', $step);
                }
            }

            // assemble() sets requires_human_review/review_status from the lint.
            $post = $this->assembler->assemble(
                $user, $campaign, $approvedText, $lint, $slideUrls, $officialImage,
                $postType, $this->text->providerLabel($model), $design, $engine
            );

            // Red-flag or campaign-forced review: leave as a draft in the queue.
            // Otherwise push it live now (automation = no review).
            if ($post->requires_human_review && $post->review_status !== 'approved') {
                $step->update(['status' => 'review', 'post_id' => $post->id]);
                return;
            }

            // The assembler already attached the slides + official image, so
            // create the platform rows and publish WITHOUT re-attaching media
            // (finalizeReadyPost with [] just transitions status + dispatches).
            $this->autoPublish->preparePendingPublish($post, ['platforms' => $step->campaign->platforms]);
            $this->autoPublish->finalizeReadyPost($post, []);
            $step->update(['status' => 'queued', 'post_id' => $post->id]);
        } catch (\Throwable $e) {
            if ($hold > 0) {
                $this->credits->refund($user, $hold, 'Automation refund: product promo failed', $step);
            }
            throw $e;
        }
    }

    // ---- content helpers ----------------------------------------------------

    protected function chapter(?int $chapterId): Chapter
    {
        if (! $chapterId) {
            throw new \RuntimeException('This step has no chapter selected.');
        }
        $chapter = Chapter::join('book_chapters', 'chapters.chapter', '=', 'book_chapters.chapter')
            ->where('chapters.id', $chapterId)
            ->select('chapters.*', 'book_chapters.chapter_title as chapter_title')
            ->first();
        if (! $chapter) {
            $chapter = Chapter::find($chapterId);
        }
        if (! $chapter) {
            throw new \RuntimeException("Chapter {$chapterId} not found.");
        }
        return $chapter;
    }

    /** Generate post text (title/caption/image_text/hashtags) for a book chapter. */
    protected function bookText(Chapter $chapter, string $model, ?string $extra): array
    {
        $system = CoreThesis::worldview() . "\n\n"
            . "You write Instagram post copy for the science book 'The Carbonated Body' by Steven Scott, "
            . "from the author's worldview above. Use the chapter as raw material to make ONE sharp, profound point. "
            . "The narrator is NOT the author; refer to it as 'The Carbonated Body' by Steven Scott, never 'my book'. "
            . "Avoid medical/clinical language and words like cure, treat, heal, prevent, fix.\n\n"
            . "Respond ONLY with a JSON object with these keys: "
            . '{"title": string (short hook), "caption": string (2-4 sentence social caption), '
            . '"image_text": string (the short, punchy text to render ON the image - <= 45 words), '
            . '"hashtags": string (5-8 space-separated hashtags)}.' . "\n\n"
            . "Chapter '{$chapter->chapter}: {$chapter->chapter_title}':\n" . $chapter->content;

        $prompt = 'Write the post.' . ($extra ? ' Additional direction: ' . $extra : '');
        return $this->text->generateJson($model, $system, $prompt);
    }

    /** Generate a spoken video script (plain text) for a book chapter. */
    protected function videoScript(Chapter $chapter, string $model, int $duration, ?string $extra): string
    {
        $words = (int) round($duration * 2.5);
        $system = CoreThesis::worldview() . "\n\n"
            . "You are a scriptwriter for short-form video based on 'The Carbonated Body' by Steven Scott, "
            . "writing from the author's worldview above - not a neutral recap. Make ONE sharp, profound point. "
            . "The narrator is NOT the author; never say 'my book' or 'I wrote'. Refer to it as 'The Carbonated Body' "
            . "by Steven Scott. Avoid medical/clinical words like cure, treat, heal, prevent, fix. "
            . "Ground factual claims in the chapter content; do not fabricate studies or numbers.\n\n"
            . "Respond ONLY with a JSON object: {\"script\": string}. The script is spoken words only - no scene "
            . "directions, brackets, markdown, hashtags, or emojis. Target about {$words} words.\n\n"
            . "Chapter '{$chapter->chapter}: {$chapter->chapter_title}':\n" . $chapter->content;

        $prompt = 'Write the script.' . ($extra ? ' Additional direction: ' . $extra : '');
        $out = $this->text->generateJson($model, $system, $prompt);
        $script = trim($out['script'] ?? '');
        if (mb_strlen($script) < 10) {
            throw new \RuntimeException('Generated script was empty.');
        }
        return $script;
    }

    protected function caption(array $text): string
    {
        return trim(($text['title'] ?? '') . (isset($text['title'], $text['caption']) ? "\n" : '') . ($text['caption'] ?? ''));
    }

    /**
     * Faithful book-slide image prompt (mirrors AiPostGenerationController's
     * infographic style): title + chapter subtitle, the exact approved text
     * rendered verbatim, the real book cover as a static corner thumbnail.
     */
    protected function bookSlidePrompt(Chapter $chapter, string $imageText): string
    {
        $coverPath = Storage::disk('public')->path('assets/cover-image.png');
        $label = $chapter->chapter ?? '';
        $title = $chapter->chapter_title ?? '';

        return "Create a clean, professional medical educational infographic in a 4:5 vertical format optimized for Instagram.
TITLE: 'The Carbonated Body' (large, elegant serif font at the top).
SUBTITLE: '{$label}: {$title}' (below the title).

VISUAL CENTERPIECE: a clear, scientific, on-topic illustration for this chapter. Cinematic, high-contrast, professional.

CONTENT SECTION - TEXT ACCURACY IS HIGHEST PRIORITY.
On a clean semi-transparent overlay or clear negative space, render this EXACT text, verbatim and unchanged - do not summarize, paraphrase, shorten, or reword it in any way:
{$imageText}

STRICT RULES: Render the approved text exactly, character for character. Perfect spelling. Clean modern sans-serif, large and readable, strong contrast, even spacing. No hashtags, no special symbols, no gibberish, no warped or overlapping text. Center-aligned, generous spacing, keep all text inside safe readable zones.

THUMBNAIL: In the bottom-right corner place the EXACT book cover image from {$coverPath} as a static thumbnail. Do NOT redesign, recolor, or regenerate the cover.";
    }
}
