<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Api\v1\ResponseController;
use App\Models\Chapter;
use App\Models\HeygenGeneration;
use App\Models\HeygenPhotoAvatar;
use App\Models\HeygenUserFavoriteAvatar;
use App\Models\Post;
use App\Services\CreditService;
use App\Services\HeygenGenerationPoller;
use App\Services\HeygenPricingService;
use App\Services\HeygenService;
use App\Services\PostAutoPublishService;
use App\Services\VideoOutroService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenAI\Laravel\Facades\OpenAI;

class HeygenController extends ResponseController
{
    public function __construct(
        protected HeygenService $heygen,
        protected CreditService $credits,
        protected HeygenPricingService $pricing,
        protected VideoOutroService $outro,
        protected PostAutoPublishService $autoPublish,
    ) {}

    /**
     * Kick off a Video Agent generation from a prompt. Credits are deducted
     * up front so a user can never trigger a generation they can't pay for;
     * if the HeyGen request itself fails, the deduction is refunded
     * immediately.
     */
    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'script' => 'required|string|min:10|max:10000',
            'duration_seconds' => 'required|numeric|min:10|max:120',
            'orientation' => 'nullable|in:portrait,landscape',
            'avatar_id' => 'nullable|string',
            'voice_id' => 'nullable|string',
            'post_id' => 'nullable|exists:posts,id',

            // Publish-without-review: when set, a Post is created immediately
            // (instead of after the user reviews the finished video) and
            // handed off to PostAutoPublishService, which the background
            // heygen:poll-pending-publish command finishes once the render
            // completes - see PostAutoPublishService for the full flow.
            'publish_action' => 'nullable|in:review,publish_now,schedule',
            'chapter_id' => 'required_if:publish_action,publish_now,schedule|nullable|exists:chapters,id',
            'caption' => 'required_if:publish_action,publish_now,schedule|nullable|string',
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

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }

        $user = Auth::user();
        $durationSeconds = (float) $request->duration_seconds;

        // Duration is chosen by the user upfront now, so the hold can be
        // computed exactly instead of guessing a worst-case length. HeyGen's
        // actual render can still come out a little different, which is why
        // reconcileActualCost() below still adjusts once it's known.
        $cost = $this->pricing->creditsForDuration($durationSeconds);

        if (! $this->credits->hasSufficientBalance($user, $cost)) {
            return $this->sendError(
                "Not enough credits. This video requires a {$cost} credit hold, you have {$user->credits_balance}.",
                ['credits_required' => $cost, 'credits_balance' => $user->credits_balance],
                422
            );
        }

        $deducted = $this->credits->deduct($user, $cost, 'HeyGen video generation (estimated hold)');

        if (! $deducted) {
            // Balance changed between the check above and the deduction attempt
            // (concurrent request) - fail safely rather than generate for free.
            return $this->sendError('Not enough credits.', [], 422);
        }

        try {
            // Same personalized link already stamped onto image posts (see
            // PublishPostToSocialMedia's caption builder) - HeyGen videos
            // should carry it too, both on-screen and spoken aloud.
            $affiliateUrl = $user->affiliate_id
                ? rtrim(Config::get('constant.frontend_url'), '/').'/'.$user->affiliate_id
                : null;

            $result = $this->heygen->generateFromPrompt([
                'prompt' => $request->script,
                'duration_seconds' => $durationSeconds,
                'orientation' => $request->orientation ?? 'portrait',
                'avatar_id' => $request->avatar_id,
                'voice_id' => $request->voice_id,
                'affiliate_url' => $affiliateUrl,
            ]);

            $generation = HeygenGeneration::create([
                'user_id' => $user->id,
                'post_id' => $request->post_id,
                'heygen_session_id' => $result['session_id'],
                'heygen_video_id' => $result['video_id'],
                'prompt' => $request->script,
                'status' => $result['status'],
                'credits_charged' => $cost,
                'request_payload' => $result['request_payload'],
            ]);

            $publishAction = $request->input('publish_action', 'review');

            if ($publishAction !== 'review') {
                $post = $this->autoPublish->createPendingPost($user, (int) $request->chapter_id, $request->caption, 'heygen');
                $generation->post_id = $post->id;
                $generation->save();

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
                'heygen_session_id' => $generation->heygen_session_id,
                'status' => $generation->status,
                'post_id' => $generation->post_id,
                'publish_action' => $publishAction,
                'credits_balance' => $user->fresh()->credits_balance,
            ], 'Video generation started', 201);
        } catch (\Throwable $e) {
            // HeyGen request failed before we even got a session_id - refund
            // immediately since nothing was generated.
            $this->credits->refund($user, $cost, 'Refund: HeyGen request failed before session was created');

            Log::error('HeyGen generation failed', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            return $this->sendError('Video generation failed to start', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Poll the status of a generation. This is a two-stage process on
     * HeyGen's side: first the agent session must produce a video_id
     * (session status: thinking -> generating), then that video_id has its
     * own render status to poll separately. Refunds credits automatically
     * the first time a generation is observed to have failed. The actual
     * logic lives in HeygenGenerationPoller so the same polling (and its
     * completion hook into PostAutoPublishService) also runs from the
     * heygen:poll-pending-publish console command, without a browser.
     */
    public function status(Request $request, int $id, HeygenGenerationPoller $poller)
    {
        $generation = HeygenGeneration::where('user_id', Auth::id())->findOrFail($id);

        try {
            $poller->advance($generation);

            return $this->sendResponse($generation, 'Generation status retrieved successfully', 200);
        } catch (\Throwable $e) {
            Log::error('HeyGen status check failed', ['error' => $e->getMessage(), 'generation_id' => $generation->id]);
            return $this->sendError('Could not check generation status', ['error' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        $generations = Auth::user()
            ->heygenGenerations()
            ->orderByDesc('id')
            ->paginate(20);

        return $this->sendResponse($generations, 'Generations retrieved successfully', 200);
    }

    public function avatars(Request $request)
    {
        try {
            $avatars = $this->heygen->listAvatars();

            // Per-user visibility: everyone shares one HeyGen account, so
            // user-created photo avatars are claimed in
            // heygen_photo_avatars - a claimed group is visible only to
            // its creator, unclaimed groups (curated ones made directly in
            // HeyGen's UI) stay visible to all. "deleted" tombstones
            // (HeyGen refused the delete) are hidden from everyone.
            $claimRows = HeygenPhotoAvatar::get(['group_id', 'user_id', 'status']);
            $claims = $claimRows->pluck('user_id', 'group_id');
            $tombstones = $claimRows->where('status', 'deleted')->pluck('group_id')->flip();
            $avatars = array_values(array_filter($avatars, function ($a) use ($claims, $tombstones) {
                if (! ($a['is_my_avatar'] ?? false)) {
                    return true;
                }
                $groupId = $a['group_id'] ?? '';
                if (isset($tombstones[$groupId])) {
                    return false;
                }
                $owner = $claims[$groupId] ?? null;
                return $owner === null || (int) $owner === (int) Auth::id();
            }));

            // Recently-used avatars, most recent first - derived from past
            // generations' request_payload rather than a separate table,
            // since avatar_id is already recorded there whenever one was
            // explicitly chosen (nothing to migrate).
            $recentAvatarIds = Auth::user()
                ->heygenGenerations()
                ->orderByDesc('id')
                ->pluck('request_payload')
                ->pluck('avatar_id')
                ->filter()
                ->unique()
                ->take(10)
                ->values();

            $avatarsById = collect($avatars)->keyBy('avatar_id');
            $recentlyUsed = $recentAvatarIds
                ->map(fn ($id) => $avatarsById->get($id))
                ->filter()
                ->values();

            // This user's own favorites.
            $personalFavoriteIds = HeygenUserFavoriteAvatar::where('user_id', Auth::id())
                ->orderBy('id')
                ->pluck('avatar_id');
            $personalFavorites = $personalFavoriteIds
                ->map(fn ($id) => $avatarsById->get($id))
                ->filter()
                ->values();

            // Global favorites - not separately curated, just whichever
            // avatars have been favorited by the most users overall. Ranks
            // everyone's personal picks into one shared "popular" signal.
            $globalFavoriteIds = HeygenUserFavoriteAvatar::select('avatar_id')
                ->selectRaw('count(*) as favorite_count')
                ->groupBy('avatar_id')
                ->orderByDesc('favorite_count')
                ->orderBy('avatar_id')
                ->pluck('avatar_id');
            $globalFavorites = $globalFavoriteIds
                ->map(fn ($id) => $avatarsById->get($id))
                ->filter()
                ->values();

            // The account's own HeyGen "My avatars" (photo avatar groups) -
            // already merged into $avatars for search, surfaced separately
            // so the picker can show them as their own section.
            $myAvatars = collect($avatars)
                ->filter(fn ($a) => $a['is_my_avatar'] ?? false)
                ->values();

            return $this->sendResponse([
                'my_avatars' => $myAvatars,
                'personal_favorites' => $personalFavorites,
                'global_favorites' => $globalFavorites,
                'recently_used' => $recentlyUsed,
                'all' => $avatars,
            ], 'Avatars retrieved successfully', 200);
        } catch (\Throwable $e) {
            return $this->sendError('Could not retrieve avatars', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a HeyGen photo avatar from a photo the user uploads of
     * themselves. The avatar lands in the shared HeyGen account but is
     * claimed to this user (see avatars() scoping). Capped at 3 live
     * avatars per user, consent checkbox required.
     */
    public function createPhotoAvatar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:60',
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240',
            'consent' => 'required|accepted',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }

        $liveCount = HeygenPhotoAvatar::where('user_id', Auth::id())
            ->whereIn('status', ['pending', 'ready'])
            ->count();

        if ($liveCount >= 3) {
            return $this->sendError('You can have up to 3 avatars. Delete one to create another.', [], 422);
        }

        try {
            $file = $request->file('photo');
            $imageKey = $this->heygen->uploadImageAsset($file->get(), $file->getMimeType());
            $created = $this->heygen->createPhotoAvatarGroup($request->name, $imageKey);

            $avatar = HeygenPhotoAvatar::create([
                'user_id' => Auth::id(),
                'group_id' => $created['group_id'],
                'look_id' => $created['id'] ?? $created['group_id'],
                'name' => $request->name,
                'status' => ($created['status'] ?? 'pending') === 'completed' ? 'ready' : 'pending',
                'preview_image_url' => $created['image_url'] ?? null,
            ]);

            $this->heygen->forgetAvatarCache();

            return $this->sendResponse($avatar, 'Avatar is being created - it usually takes under a minute.', 201);
        } catch (\Throwable $e) {
            Log::error('Photo avatar creation failed', ['error' => $e->getMessage(), 'user_id' => Auth::id()]);
            return $this->sendError('Could not create the avatar', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * This user's own photo avatars, refreshing any still-pending ones
     * against HeyGen. Deleted tombstones stay hidden.
     */
    public function listPhotoAvatars(Request $request)
    {
        $avatars = HeygenPhotoAvatar::where('user_id', Auth::id())
            ->where('status', '!=', 'deleted')
            ->orderByDesc('id')
            ->get();

        foreach ($avatars->where('status', 'pending') as $avatar) {
            try {
                $looks = collect($this->heygen->groupLooks($avatar->group_id));
                $look = $looks->firstWhere('id', $avatar->look_id) ?? $looks->first();

                if (! $look) {
                    continue;
                }

                if (! empty($look['workflow_error']) || ! empty($look['moderation_msg'])) {
                    $avatar->update([
                        'status' => 'failed',
                        'error_message' => $look['moderation_msg'] ?: (is_array($look['workflow_error']) ? json_encode($look['workflow_error']) : $look['workflow_error']),
                    ]);
                } elseif (($look['status'] ?? '') === 'completed') {
                    $avatar->update([
                        'status' => 'ready',
                        'look_id' => $look['id'],
                        'preview_image_url' => $look['image_url'] ?? $avatar->preview_image_url,
                    ]);
                    $this->heygen->forgetAvatarCache();
                }
            } catch (\Throwable $e) {
                Log::error('Photo avatar status refresh failed', ['id' => $avatar->id, 'error' => $e->getMessage()]);
            }
        }

        return $this->sendResponse($avatars, 'Photo avatars retrieved successfully', 200);
    }

    /**
     * Delete one of this user's photo avatars. If HeyGen refuses the
     * delete, the row stays as a hidden tombstone so the group can never
     * leak into other users' pickers as "unclaimed".
     */
    public function deletePhotoAvatar(Request $request, int $id)
    {
        $avatar = HeygenPhotoAvatar::where('user_id', Auth::id())->findOrFail($id);

        if ($this->heygen->deleteAvatarGroup($avatar->group_id)) {
            $avatar->delete();
        } else {
            $avatar->update(['status' => 'deleted']);
        }

        $this->heygen->forgetAvatarCache();

        return $this->sendResponse([], 'Avatar deleted', 200);
    }

    /**
     * Toggle an avatar in/out of this user's own favorites. Every user's
     * favorites also feed the "global favorites" ranking returned by
     * avatars() - an avatar favorited by more users ranks higher there,
     * with no separate curation step needed.
     */
    public function toggleFavoriteAvatar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'avatar_id' => 'required|string',
            'avatar_name' => 'required|string',
            'preview_image_url' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }

        $existing = HeygenUserFavoriteAvatar::where('user_id', Auth::id())
            ->where('avatar_id', $request->avatar_id)
            ->first();

        if ($existing) {
            $existing->delete();

            return $this->sendResponse(['favorited' => false], 'Removed from favorites', 200);
        }

        HeygenUserFavoriteAvatar::create([
            'user_id' => Auth::id(),
            'avatar_id' => $request->avatar_id,
            'avatar_name' => $request->avatar_name,
            'preview_image_url' => $request->preview_image_url,
        ]);

        return $this->sendResponse(['favorited' => true], 'Added to favorites', 200);
    }

    /**
     * Draft (or revise) a spoken video script for the user to review before
     * anything is sent to HeyGen. Free - this is a single cheap text call,
     * same reasoning as the image caption draft step. Sized to roughly match
     * natural speaking pace for the requested duration.
     */
    public function draftScript(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'chapter' => 'required|exists:chapters,id',
            'model' => 'required|string',
            'duration_seconds' => 'required|numeric|min:10|max:120',
            'prompt' => 'nullable|string',
            'feedback' => 'nullable|string',
            'previous_script' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }

        $chapter = Chapter::join('book_chapters', 'chapters.chapter', '=', 'book_chapters.chapter')
            ->where('chapters.id', $request->chapter)
            ->select('chapters.id', 'chapters.chapter', 'chapters.content', 'book_chapters.chapter_title as chapter_title')
            ->first();
        if (! $chapter) {
            $chapter = Chapter::where('id', $request->chapter)->first();
        }

        $wordTarget = (int) round($request->duration_seconds * 2.5); // ~150 wpm natural speaking pace
        $task = trim((string) $request->prompt) !== ''
            ? $request->prompt
            : 'Write an engaging, accurate script covering the most interesting and important ideas from this chapter.';
        $prompt = "Task: {$task}";

        if ($request->feedback) {
            $prompt .= "\n\nYou previously wrote this script:\n{$request->previous_script}"
                . "\n\nThe user requested these changes: {$request->feedback}"
                . "\n\nRevise the script to address the requested changes.";
        }

        try {
            $modelChoice = $request->model;

            if (str_contains($modelChoice, 'gpt')) {
                $script = $this->fetchOpenAIScript('gpt-5.4', $prompt, $chapter, $wordTarget);
            } elseif (str_contains($modelChoice, 'claude')) {
                $script = $this->fetchClaudeScript('claude-sonnet-5', $prompt, $chapter, $wordTarget);
            } else {
                $script = $this->fetchGeminiScript($modelChoice, $prompt, $chapter, $wordTarget);
            }

            return $this->sendResponse(['script' => $script], 'Draft script generated successfully', 200);
        } catch (\Throwable $e) {
            return $this->sendError('Could not generate draft script', ['error' => $e->getMessage()], 500);
        }
    }

    protected function scriptSystemInstruction($chapter, int $wordTarget): string
    {
        return "You are a professional scriptwriter for short-form video content based on a science book titled 'The Carbonated Body'.

Write a spoken video script based on the provided chapter content and the user's request. The script will be read aloud by an AI avatar.

RULES (MANDATORY):
- The narrator is NOT the author. The book was written by Steven Scott. Never say 'my book', 'I wrote', 'in my research', or anything implying the narrator wrote it. When the book comes up, refer to it as 'The Carbonated Body' by Steven Scott (or 'the book by Steven Scott').
- Respond with the spoken script text ONLY - no scene directions, no stage directions, no brackets, no markdown, no labels like 'Script:'.
- Target approximately {$wordTarget} words, matching natural speaking pace for the requested video length.
- Clear, confident, non-sensational, educational tone.
- Do not use medical or clinical language, or words like cure, treat, heal, prevent, fix.
- Do NOT invent facts beyond the chapter content provided.
- Do not include hashtags, emojis, or captions - just the words to be spoken.

Chapter content: '{$chapter->content}'";
    }

    protected function fetchOpenAIScript($model, $prompt, $chapter, int $wordTarget): string
    {
        $result = OpenAI::chat()->create([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $this->scriptSystemInstruction($chapter, $wordTarget)],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        $script = trim($result->choices[0]->message->content ?? '');

        if (! $script) {
            throw new \RuntimeException('AI returned an empty script.');
        }

        return $script;
    }

    protected function fetchClaudeScript($model, $prompt, $chapter, int $wordTarget): string
    {
        $response = Http::withHeaders([
            'x-api-key' => Config::get('constant.claud_keys.key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => 1024,
            'system' => $this->scriptSystemInstruction($chapter, $wordTarget),
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if ($response->failed()) {
            $errorMessage = $response->json('error.message') ?? 'Unknown Claude API Error';
            throw new \RuntimeException("Claude API Error: {$errorMessage}");
        }

        $script = trim($response->json('content.0.text') ?? '');

        if (! $script) {
            throw new \RuntimeException('Claude returned an empty script.');
        }

        return $script;
    }

    protected function fetchGeminiScript($model, $prompt, $chapter, int $wordTarget): string
    {
        $response = Http::withHeaders([
            'x-goog-api-key' => Config::get('constant.gemini_keys.key'),
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro-latest:generateContent',
            [
                'system_instruction' => ['parts' => [['text' => $this->scriptSystemInstruction($chapter, $wordTarget)]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            ]
        );

        if ($response->failed()) {
            $errorMessage = $response->json('error.message') ?? 'Unknown Gemini API Error';
            throw new \RuntimeException("Gemini API Error: {$errorMessage}");
        }

        $script = trim(data_get($response->json(), 'candidates.0.content.parts.0.text') ?? '');

        if (! $script) {
            throw new \RuntimeException('Gemini returned an empty script.');
        }

        return $script;
    }
}
