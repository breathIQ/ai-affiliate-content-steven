<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HeygenService
{
    // Nullable rather than a plain string: this service is constructor-
    // injected into HeygenController, so a non-nullable type here would
    // fatal the *entire controller* (including endpoints that never touch
    // HeyGen, like draftScript()) whenever HEYGEN_API_KEY isn't set yet,
    // instead of failing only the specific request that actually needs it.
    protected ?string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.heygen.api_key');
        $this->baseUrl = rtrim(config('services.heygen.base_url'), '/');
    }

    protected function headers(): array
    {
        return [
            'X-Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * List avatars available to this HeyGen account (custom clones plus
     * HeyGen's stock library - 1000+ entries). Cached for a day since this
     * almost never changes and the raw response is large; avoids hitting
     * HeyGen on every page load of the avatar picker.
     */
    public const AVATAR_CACHE_KEY = 'heygen_avatars_v4';

    /**
     * Featured public/community avatar groups. Their looks are treated
     * like the account's own (is_my_avatar - the picker's top "My
     * Avatars" section) and lead the list in THIS array's order, ahead
     * of the account's uploads. Steven curates this by naming avatars;
     * adding one = add its group id here + run heygen:refresh-avatars.
     */
    protected const ADOPTED_PUBLIC_GROUPS = [
        '9fe4a9a286724981982942538f29732c', // Gabrielle (20 looks) - Steven's headliner
        '8af078aa4e034870a0056a23cfa396d8', // Morgan
    ];

    /**
     * Bust the cached avatar list - called whenever a user creates or
     * deletes a photo avatar so it shows up (or disappears) immediately.
     * A queued full rebuild is dispatched so the community catalog (which
     * is too slow to fetch inside a web request) comes back within about
     * a minute; until then the fast fallback list serves.
     */
    /**
     * The avatar list is several MB once the community catalog is in -
     * bigger than the database cache store can hold on this host (values
     * truncate at ~1MB and fail to unserialize). The file store has no
     * such limit, so this key always lives there regardless of
     * CACHE_STORE.
     */
    protected function avatarCache(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store('file');
    }

    public function forgetAvatarCache(): void
    {
        $this->avatarCache()->forget(self::AVATAR_CACHE_KEY);
        \App\Jobs\RefreshHeygenAvatarCache::dispatch();
    }

    public function listAvatars(): array
    {
        $cached = $this->avatarCache()->get(self::AVATAR_CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        // Cold fallback, built inside a web request: stock + the account's
        // own groups only. The full community catalog (500+ groups, one
        // looks-call each) is far too slow for a request path - it's built
        // by the heygen:refresh-avatars command (nightly + queued after
        // cache busts) and replaces this with a longer-lived entry.
        $avatars = array_merge($this->listMyAvatarLooks(false), $this->listStockAvatars());
        $this->avatarCache()->put(self::AVATAR_CACHE_KEY, $avatars, now()->addHours(6));

        return $avatars;
    }

    /**
     * The full avatar list including HeyGen's public/UGC/community photo
     * avatar catalog. Takes 1-2 minutes (hundreds of per-group calls) -
     * only ever run from the console command / queued job, never inline.
     */
    public function buildFullAvatarCache(): array
    {
        $avatars = array_merge($this->listMyAvatarLooks(true), $this->listStockAvatars());

        // Long TTL: refreshed nightly by the scheduler; if a refresh run
        // fails, yesterday's full list keeps serving instead of users
        // falling back to the small list.
        $this->avatarCache()->put(self::AVATAR_CACHE_KEY, $avatars, now()->addDays(7));

        return $avatars;
    }

    protected function listStockAvatars(): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(90)
            ->get("{$this->baseUrl}/v2/avatars");

        if ($response->failed()) {
            Log::error('HeyGen list avatars failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('HeyGen list avatars failed: '.$response->body());
        }

        $avatars = $response->json('data.avatars') ?? [];

        // Exclude the account owner's own custom avatar clones - these
        // are personal/test avatars, not meant to be offered as generic
        // options for video generation.
        $avatars = array_filter($avatars, fn ($a) => ! str_starts_with($a['avatar_name'], 'Steven Scott'));

        return array_map(fn ($a) => [
            'avatar_id' => $a['avatar_id'],
            'avatar_name' => $a['avatar_name'],
            'gender' => $a['gender'],
            'preview_image_url' => $a['preview_image_url'],
            'preview_video_url' => $a['preview_video_url'],
            'premium' => $a['premium'],
        ], array_values($avatars));
    }

    /**
     * Looks from avatar groups, flattened into the same shape as stock
     * avatars. Two modes:
     * - $includeCommunity=false (fast, web-request safe): only the
     *   account's own PRIVATE groups plus the pinned adopted public ones.
     * - $includeCommunity=true (slow, command/job only): the entire
     *   public/UGC/community photo-avatar catalog too (500+ groups, one
     *   looks-call each, batched gently for rate limits).
     * is_my_avatar marks the account's own groups (the picker's "My
     * Avatars" section + per-user claims); community looks carry
     * is_community instead and appear in search/browse only. Failures
     * return [] rather than breaking the whole picker over a side list.
     */
    protected function listMyAvatarLooks(bool $includeCommunity): array
    {
        try {
            $groupsResponse = Http::withHeaders($this->headers())
                ->timeout(90)
                ->get("{$this->baseUrl}/v2/avatar_group.list", [
                    'include_public' => $includeCommunity ? 'true' : 'false',
                ]);

            if ($groupsResponse->failed()) {
                Log::error('HeyGen avatar_group.list failed', ['status' => $groupsResponse->status(), 'body' => $groupsResponse->body()]);
                return [];
            }

            // Group types observed: GENERATED_PHOTO / PHOTO / PRIVATE are
            // the account's own; PUBLIC / PUBLIC_PHOTO / COMMUNITY_PHOTO
            // are HeyGen's shared catalog.
            $ownTypes = ['GENERATED_PHOTO', 'PHOTO', 'PRIVATE'];

            $groups = collect($groupsResponse->json('data.avatar_group_list') ?? [])
                // Same rule as stock: the owner's personal clone groups
                // aren't offered as generic options.
                ->filter(fn ($g) => ! str_starts_with($g['name'] ?? '', 'Steven Scott'))
                ->map(fn ($g) => $g + [
                    'is_own' => in_array($g['group_type'] ?? '', $ownTypes, true)
                        || in_array($g['id'] ?? '', self::ADOPTED_PUBLIC_GROUPS, true),
                ])
                ->values();

            // Adopted public groups aren't in the private-only list - pin
            // them in (name resolved from their looks below).
            foreach (self::ADOPTED_PUBLIC_GROUPS as $publicGroupId) {
                if (! $groups->contains(fn ($g) => ($g['id'] ?? '') === $publicGroupId)) {
                    $groups->push(['id' => $publicGroupId, 'name' => null, 'is_own' => true]);
                }
            }

            if ($groups->isEmpty()) {
                return [];
            }

            // Ordering = display order: featured pins first (in constant
            // order), then the account's own groups, then community.
            $pinnedOrder = array_flip(self::ADOPTED_PUBLIC_GROUPS);
            $groups = $groups->sortBy(function ($g) use ($pinnedOrder) {
                $id = $g['id'] ?? '';
                if (isset($pinnedOrder[$id])) {
                    return $pinnedOrder[$id];
                }
                return $g['is_own'] ? 1000 : 2000;
            })->values();

            // Fetch each group's looks in gentle batches - the community
            // catalog is 500+ groups and hammering HeyGen concurrently
            // risks rate limiting.
            $responses = [];
            foreach ($groups->chunk(15) as $chunk) {
                $responses += Http::pool(fn ($pool) => $chunk->map(
                    fn ($g) => $pool->as($g['id'])
                        ->withHeaders($this->headers())
                        ->timeout(30)
                        ->get("{$this->baseUrl}/v2/avatar_group/{$g['id']}/avatars")
                )->all());

                if ($groups->count() > 15) {
                    usleep(250000);
                }
            }

            $looks = [];

            foreach ($groups as $group) {
                $res = $responses[$group['id']] ?? null;

                if (! $res instanceof \Illuminate\Http\Client\Response || $res->failed()) {
                    continue;
                }

                $groupLooks = $res->json('data.avatar_list') ?? [];
                $lookIndex = 0;

                foreach ($groupLooks as $look) {
                    // Some looks come back incomplete (mid-processing or
                    // failed uploads) - skip anything without an id.
                    if (empty($look['id'])) {
                        continue;
                    }

                    $lookIndex++;
                    $lookName = trim($look['name'] ?? '');
                    // Pinned public groups arrive without a name - fall
                    // back to the look's own name.
                    $groupName = $group['name'] ?? ($lookName !== '' ? $lookName : 'Avatar');

                    $displayName = count($groupLooks) > 1 && $lookName !== '' && $lookName !== $groupName
                        ? "{$groupName} - {$lookName}"
                        : $groupName;

                    // Unnamed looks in a multi-look group would all show
                    // the same label - number them so they're tellable
                    // apart ("Gabrielle", "Gabrielle 2", ...).
                    if ($displayName === $groupName && count($groupLooks) > 1 && $lookIndex > 1) {
                        $displayName = "{$groupName} {$lookIndex}";
                    }

                    $looks[] = [
                        'avatar_id' => $look['id'],
                        'avatar_name' => $displayName,
                        'gender' => null,
                        'preview_image_url' => $look['image_url'] ?? null,
                        'preview_video_url' => $look['motion_preview_url'] ?? null,
                        'premium' => false,
                        'is_my_avatar' => (bool) $group['is_own'],
                        'is_community' => ! $group['is_own'],
                        // HeyGen catalog category (PUBLIC / PUBLIC_PHOTO /
                        // COMMUNITY_PHOTO / own types) - drives the Avatars
                        // page tabs. Stock avatars have no group_type.
                        'group_type' => $group['group_type'] ?? 'PINNED',
                        // Lets the controller scope user-created avatars to
                        // their creator (heygen_photo_avatars claims).
                        'group_id' => $group['id'],
                    ];
                }
            }

            return $looks;
        } catch (\Throwable $e) {
            Log::error('HeyGen avatar looks failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Upload an image to HeyGen's asset store and return its image_key -
     * the identifier photo-avatar creation expects. This is the
     * upload.heygen.com raw-binary endpoint, NOT /v3/assets (whose
     * asset_id photo_avatar/avatar_group/create does not accept).
     */
    public function uploadImageAsset(string $contents, string $mime): string
    {
        $response = Http::withHeaders(['X-Api-Key' => $this->apiKey, 'Content-Type' => $mime])
            ->withBody($contents, $mime)
            ->timeout(120)
            ->post('https://upload.heygen.com/v1/asset');

        if ($response->failed() || ! $response->json('data.image_key')) {
            Log::error('HeyGen image asset upload failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('HeyGen image upload failed: '.$response->body());
        }

        return $response->json('data.image_key');
    }

    /**
     * Create a photo avatar group from an uploaded image. Returns the
     * created look (id doubles as group_id for the first look); it starts
     * status=pending and typically completes within seconds.
     */
    public function createPhotoAvatarGroup(string $name, string $imageKey): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(120)
            ->post("{$this->baseUrl}/v2/photo_avatar/avatar_group/create", [
                'name' => $name,
                'image_key' => $imageKey,
            ]);

        if ($response->failed() || ! $response->json('data.group_id')) {
            Log::error('HeyGen photo avatar group create failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('HeyGen avatar creation failed: '.($response->json('error.message') ?? $response->body()));
        }

        return $response->json('data');
    }

    /**
     * Raw looks of one avatar group - used to poll a newly created photo
     * avatar until its look reports completed (or a moderation/workflow
     * failure).
     */
    public function groupLooks(string $groupId): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/v2/avatar_group/{$groupId}/avatars");

        if ($response->failed()) {
            throw new \RuntimeException('HeyGen group looks failed: '.$response->body());
        }

        return $response->json('data.avatar_list') ?? [];
    }

    /**
     * Delete an avatar group on HeyGen's side. Returns whether HeyGen
     * confirmed the delete - the caller keeps the claim row as a tombstone
     * when this fails, so an undeleted group never leaks back into other
     * users' pickers as "unclaimed".
     */
    public function deleteAvatarGroup(string $groupId): bool
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(30)
                ->delete("{$this->baseUrl}/v2/avatar_group/{$groupId}");

            if ($response->failed()) {
                Log::error('HeyGen avatar group delete failed', ['group_id' => $groupId, 'status' => $response->status(), 'body' => $response->body()]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('HeyGen avatar group delete threw', ['group_id' => $groupId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    protected function coverImageUrl(): string
    {
        return config('services.heygen.outro_image_url')
            ?: rtrim(config('app.url'), '/').'/assets/cover-image.png';
    }

    // Prefer a pre-uploaded asset_id over a URL: HeyGen fetches "url"-type
    // files from its own servers, and that fetch can be blocked by the
    // hosting provider's WAF even when the URL works fine in a browser.
    // asset_id sidesteps the fetch entirely (see config/services.php).
    protected function coverImageFile(): array
    {
        $assetId = config('services.heygen.outro_asset_id');

        return $assetId
            ? ['type' => 'asset_id', 'asset_id' => $assetId]
            : ['type' => 'url', 'url' => $this->coverImageUrl()];
    }

    /**
     * Kick off a Video Agent generation from a plain text prompt. HeyGen's
     * agent automatically picks the avatar, voice, and style unless they're
     * explicitly provided. Returns a session_id; the video_id is not known
     * yet and is obtained by polling getSessionStatus().
     *
     * $params example:
     * [
     *   'prompt' => 'Create a short vertical video explaining...',
     *   'orientation' => 'portrait', // or 'landscape', auto-detected if omitted
     *   'avatar_id' => null, // optional - omit to let the agent choose
     *   'voice_id' => null,  // optional - omit to let the agent choose
     *   'affiliate_url' => 'https://co2body.com/stevenscott', // this user's personal link - shown on the closing frame and spoken aloud
     * ]
     */
    public function generateFromPrompt(array $params): array
    {
        $prompt = $params['prompt'];
        $requestCoverOutro = (bool) config('services.heygen.request_cover_outro_via_prompt');
        $affiliateUrl = $params['affiliate_url'] ?? null;

        if (! empty($params['duration_seconds'])) {
            $prompt .= " Target video length: approximately {$params['duration_seconds']} seconds.";
        }

        // HeyGen's agent can rewrite or extend the narration on its own, so
        // the attribution rule has to be repeated here, not just in the
        // script-drafting step: the presenter is never the author.
        $prompt .= " IMPORTANT: the presenter is NOT the author of the book. If the book is mentioned, refer to it as \"The Carbonated Body\" by Steven Scott (or \"the book by Steven Scott\") - never as \"my book\" or the presenter's own work.";

        if ($requestCoverOutro) {
            $closingText = $affiliateUrl ?: 'Visit TheCarbonatedBody.com';
            $prompt .= " IMPORTANT: end the video with the attached book cover image held on screen, unmodified, as the final closing shot for the last 2-3 seconds, with on-screen text directly below the book cover reading \"{$closingText}\".";

            if ($affiliateUrl) {
                $prompt .= " Also, in the last sentence of the spoken narration, have the presenter say this website out loud: \"{$affiliateUrl}\".";
            }
        }

        $payload = array_filter([
            'prompt' => $prompt,
            'orientation' => $params['orientation'] ?? 'portrait',
            'avatar_id' => $params['avatar_id'] ?? null,
            'voice_id' => $params['voice_id'] ?? null,
            'files' => $requestCoverOutro ? [$this->coverImageFile()] : null,
        ], fn ($value) => $value !== null);

        $response = Http::withHeaders($this->headers())
            ->timeout(60)
            ->post("{$this->baseUrl}/v3/video-agents", $payload);

        if ($response->failed()) {
            Log::error('HeyGen video-agent request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('HeyGen video agent request failed: '.$response->body());
        }

        $data = $response->json();
        $body = $data['data'] ?? $data;
        $sessionId = $body['session_id'] ?? null;

        if (! $sessionId) {
            throw new \RuntimeException('HeyGen response did not include a session_id: '.$response->body());
        }

        return [
            'session_id' => $sessionId,
            'status' => $body['status'] ?? 'thinking',
            'video_id' => $body['video_id'] ?? null,
            'raw' => $data,
            'request_payload' => $payload,
        ];
    }

    /**
     * Poll the video-agent session. Returns status in
     * ['thinking','generating','completed','failed'] plus video_id once the
     * agent has started rendering (still need getVideoStatus() after that
     * for the final video_url).
     */
    public function getSessionStatus(string $sessionId): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/v3/video-agents/{$sessionId}");

        if ($response->failed()) {
            Log::error('HeyGen session status check failed', [
                'session_id' => $sessionId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('HeyGen session status check failed: '.$response->body());
        }

        $data = $response->json();
        $body = $data['data'] ?? $data;

        return [
            'status' => $body['status'] ?? 'thinking',
            'video_id' => $body['video_id'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * Once a video_id is known, poll this for final render status and the
     * completed video_url.
     */
    public function getVideoStatus(string $videoId): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/v3/videos/{$videoId}");

        if ($response->failed()) {
            Log::error('HeyGen video status check failed', [
                'video_id' => $videoId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('HeyGen video status check failed: '.$response->body());
        }

        $data = $response->json();
        $body = $data['data'] ?? $data;

        return [
            'status' => $body['status'] ?? 'pending',
            'video_url' => $body['video_url'] ?? null,
            'duration' => $body['duration'] ?? null,
            'error' => $body['error'] ?? null,
            'raw' => $data,
        ];
    }
}
