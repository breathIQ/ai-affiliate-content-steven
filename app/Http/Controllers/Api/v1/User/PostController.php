<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\{StorePostRequest};
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Facades\Auth;
use App\Models\{PostPlatform,PostMedia,Post,Chapter};
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\{SocialTokenService};
use App\Services\PostMediaService;
use App\Jobs\PublishPostToSocialMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Facades\Config;


class PostController extends ResponseController
{
    public function __construct(protected PostMediaService $postMedia) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $limit = $request->input('per_page', 10);
            $search = $request->input('search');

            $query = $user->posts()
                ->with(['chapter', 'media'])
                ->when($search, function ($q) use ($search) {
                    $q->where(function ($sub) use ($search) {
                        $sub->where('caption', 'like', "%{$search}%");
                            // ->orWhere('script', 'like', "%{$search}%")
                    });
                })
                ->orderBy('created_at', 'desc');

            $posts = $query->paginate($limit);

            $data = $posts->getCollection()->map(function ($post) {
                // Get primary media
                $media = $post->media->sortBy('media_order')->first();
                // $mediaUrl = $media ? asset(Storage::url($media->media_path)) : null;
                $mediaUrl = $media ? Config::get('constant.media_base_url').config('constant.media_base_path').$media->media_path : null;
                $media_type = $media ? $media->media_type : null;
                // Parse hashtags
                $hashtagsCount = 0;
                if ($post->hastag) {
                    $decoded = json_decode($post->hastag, true);
                    if (is_array($decoded)) {
                        $hashtagsCount = count($decoded);
                    } else {
                        $hashtagsCount = count(array_filter(explode(',', $post->hastag)));
                    }
                }

                return [
                    'id' => $post->id,
                    'media' => $mediaUrl,
                    'media_type' => $media_type,
                    'post_content' => Str::limit($post->caption ?? '', 80),
                    'chapter_name' => $post->chapter_title.'...' ?? 'N/A',
                    'chapter_code' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $post->chapter_name ?? 'N/A'), // e.g. Ch-12
                    'hashtags_count' => $hashtagsCount,
                    'ai_model' => $post->ai_model,
                    'ai_generated' => true,
                    // 'status' => $post->status !='published' ? 'Failed' : 'Published',
                    'status' => $post->status,
                    'published_at' => $post->published_at,
                    'scheduled_at' => $post->scheduled_at,
                    'created_at' => $post->created_at->format('M d, Y')
                ];
            });

            return $this->sendResponse([
                'posts' => $data,
                'pagination' => [
                    'dta' => $posts->count(),
                    'total' => $posts->total(),
                    'current_page' => $posts->currentPage(),
                    'last_page' => $posts->lastPage(),
                    'per_page' => $posts->perPage(),
                ]
            ], 'Posts fetched successfully', 200);

        } catch (\Exception $e) {
            return $this->sendError('Failed to fetch posts', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $user = Auth::user();
            $post = $user->posts()->with(['chapter', 'media'])->find($id);

            if (!$post) {
                return $this->sendError('Post not found', [], 404);
            }

            // Format Media
            $formattedMedia = $post->media->sortBy('media_order')->map(function($m) {
                return [
                    'id' => $m->id,
                    'media_type' => $m->media_type,
                    // 'url' => asset(Storage::url($m->media_path)),
                    'url' => Config::get('constant.media_base_url').config('constant.media_base_path').$m->media_path,
                    'order' => $m->media_order
                ];
            });

            // Published platforms
            // $published_platforms = $post->platforms()->where('status', 'published')->pluck('platform')->toArray();
            $published_platforms = $post->platforms()->pluck('platform')->toArray();
            // Total clicks across all platforms
            $total_clicks = $post->total_clicks;
            // Clicks per platform
            $platform_clicks = PostPlatform::where('post_id', $post->id)
               // ->where('status', 'published')
                ->pluck('clicks', 'platform')
                ->toArray();

            $data = [
                'id' => $post->id,
                'chapter' => [
                    'id' => $post->chapter_id,
                    'chapter_title' => $post->chapter_title.'...' ?? 'N/A',
                    'chapter' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $post->chapter_name ?? 'N/A'),
                ],
                'caption' => $post->caption,
                // 'script' => $post->script,
                'hashtags' => $post->hastag,
                'hashtags_count' => $post->hastag ? count(json_decode($post->hastag, true) ?: explode(',', $post->hastag)) : 0,
                'ai_model' => $post->ai_model,
                'ai_prompt' => $post->ai_prompt,
                'ai_generation_params' => $post->ai_generation_params ? json_decode($post->ai_generation_params, true) : null,
                'status' => $post->status,
                'media_assets' => $post->media_assets,
                'media' => $formattedMedia,
                'scheduled_at' => $post->scheduled_at,
                'published_at' => $post->published_at,
                'created_at' => $post->created_at->format('Y-m-d H:i:s'),
                'affiliate_url' => $post->affiliate_url,
                'platform_clicks' => $platform_clicks,
                'total_clicks' => $total_clicks,
                'published_platforms' => $published_platforms,
            ];

            return $this->sendResponse($data, 'Post retrieved successfully', 200);

        } catch (\Exception $e) {
            return $this->sendError('Failed to fetch post', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $user = Auth::user();
            $post = $user->posts()->with('media')->find($id);

            if (!$post) {
                return $this->sendError('Post not found', [], 404);
            }

            // Delete media files from storage
            foreach ($post->media as $media) {
                if (Storage::disk('public')->exists($media->media_path)) {
                    Storage::disk('public')->delete($media->media_path);
                }
            }

            $post->delete();

            return $this->sendResponse([], 'Post deleted successfully', 200);

        } catch (\Exception $e) {
            return $this->sendError('Failed to delete post', ['error' => $e->getMessage()], 500);
        }
    }

    public function store(StorePostRequest $request)
    {
        $user = Auth::user();
        $chapterName = Chapter::join('book_chapters', 'chapters.chapter', '=', 'book_chapters.chapter')
            ->where('chapters.id', $request->chapter_id)
            ->select('chapters.id', 'chapters.chapter', 'book_chapters.chapter_title as chapter_title')
            ->first();
        
        if (!$chapterName) {
            $chapterName = Chapter::where('id', $request->chapter_id)->first();
            
        }

        return DB::transaction(function () use ($request,$user,$chapterName) {

            $isDraft = $request->status === 'draft';
            $isScheduled = $request->status === 'scheduled' && $request->filled('scheduled_at');

            // 1. Create Post
            $post = Post::create([
                'user_id'      => $user->id,
                'chapter_id'   => $request->chapter_id,
                'caption'      => $request->caption,
                // 'script'       => $request->script,
                'media_assets'    => $request->media_assets,
                'status'       => $isDraft ? 'draft' : ($isScheduled ? 'scheduled' : 'processing'),
                'ai_model'     => $request->ai_model,
                'ai_prompt'     => $request->ai_prompt,
                'ai_generation_params' => $request->ai_generation_params,
                'scheduled_at' => $isScheduled ? $request->scheduled_at : null,
                // 'published_at'=> $request->status === 'published' ? now() : null,
                 'published_at'=> null,
                'hastag' => $request->hashtags,
                'affiliate_url' => $user->amazon_link ?: env('AMAZON_URL'),
                'chapter_name' => $chapterName->chapter,
                'chapter_title' => $chapterName->chapter_title,
            ]);

            // 2. Media (File Upload)
            $this->postMedia->attachFromRequest($post, $request);


            // 3. Platforms (Publish Selection)
            if ($request->filled('platforms')) {
                foreach ($request->platforms as $platform) {
                    if($platform == 'tiktok') {
                        $tiktok_payload = json_encode([
                                'content_disclose' => $request->content_disclose,
                                'brand_organic' => $request->brand_organic,
                                'branded_content' => $request->branded_content,
                                'allow_comment' => $request->allow_comment,
                                'allow_duet' => $request->allow_duet,
                                'allow_stitch' => $request->allow_stitch,
                                'privacy_level' => $request->privacy_level,
                            ]);
                    }else{
                        $tiktok_payload = null;
                    }
                    
                    PostPlatform::create([
                        'post_id' => $post->id,
                        'platform' => $platform,
                        'status' => 'processing',
                        'tiktok_payload' => $tiktok_payload
                    ]);
                }
            }

            if ($isDraft) {
                return $this->sendResponse($post, 'Post saved to your library. Publish it whenever you\'re ready.', 201);
            }

            if ($isScheduled) {
                return $this->sendResponse($post, 'Post scheduled for ' . $post->scheduled_at->format('M d, Y g:i A') . '.', 201);
            }

            // Dispatch the Job to the background
            PublishPostToSocialMedia::dispatch($post);

            return $this->sendResponse($post, 'Your content is being processed and may take a few minutes to appear on your profile.', 201);

        });
    }

    /**
     * Replace a draft's media - used right after "Turn into Video" (or any
     * future in-place regeneration) so a newly-generated, already-paid-for
     * asset is persisted immediately instead of only living in the
     * browser's memory until some later save action.
     */
    public function updateMedia(Post $post, Request $request)
    {
        $user = Auth::user();

        if ($post->user_id != $user->id) {
            return $this->sendError('Unauthorized', [], 403);
        }

        if ($post->status !== 'draft') {
            return $this->sendError('Only draft posts can have their media replaced.', [], 422);
        }

        $request->validate([
            'media' => 'required|array|min:1',
        ]);

        return DB::transaction(function () use ($post, $request) {
            // keep_files: remove the old media rows but leave their files
            // on disk - set when swapping an image for a generated video,
            // so "keep the image instead" can re-attach the original later.
            $this->postMedia->clearExisting($post, ! $request->boolean('keep_files'));

            $this->postMedia->attachFromRequest($post, $request);

            if ($request->filled('caption')) {
                $post->update(['caption' => $request->caption]);
            }

            if ($request->filled('media_assets')) {
                $post->update(['media_assets' => $request->media_assets]);
            }

            return $this->sendResponse($post->fresh('media'), 'Draft updated', 200);
        });
    }

    /**
     * Publish an existing draft post to the selected platforms.
     */
    public function publish(Post $post, Request $request)
    {
        $user = Auth::user();

        if ($post->user_id != $user->id) {
            return $this->sendError('Unauthorized', [], 403);
        }

        $validated = $request->validate([
            'platforms' => 'required|array',
            'platforms.*' => 'in:instagram,instagram_story,tiktok',
            'content_disclose' => 'nullable',
            'brand_organic' => 'nullable',
            'branded_content' => 'nullable',
            'allow_comment' => 'nullable',
            'allow_duet' => 'nullable',
            'allow_stitch' => 'nullable',
            'privacy_level' => 'nullable',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        $isScheduled = $request->filled('scheduled_at');

        return DB::transaction(function () use ($post, $request, $validated, $isScheduled) {
            foreach ($validated['platforms'] as $platform) {
                $tiktok_payload = $platform === 'tiktok' ? json_encode([
                    'content_disclose' => $request->content_disclose,
                    'brand_organic' => $request->brand_organic,
                    'branded_content' => $request->branded_content,
                    'allow_comment' => $request->allow_comment,
                    'allow_duet' => $request->allow_duet,
                    'allow_stitch' => $request->allow_stitch,
                    'privacy_level' => $request->privacy_level,
                ]) : null;

                PostPlatform::create([
                    'post_id' => $post->id,
                    'platform' => $platform,
                    'status' => 'processing',
                    'tiktok_payload' => $tiktok_payload,
                ]);
            }

            if ($isScheduled) {
                $post->update([
                    'status' => 'scheduled',
                    'scheduled_at' => $validated['scheduled_at'],
                ]);

                return $this->sendResponse([], 'Post scheduled for ' . $post->scheduled_at->format('M d, Y g:i A') . '.', 200);
            }

            $post->update(['status' => 'processing']);

            PublishPostToSocialMedia::dispatch($post);

            return $this->sendResponse([], 'Your content is being processed and may take a few minutes to appear on your profile.', 200);
        });
    }

    public function repost(Post $post)
    {
        try {

            $user = Auth::user();

            // Security check (important)
            if ($post->user_id != $user->id) {
                return $this->sendError('Unauthorized', [], 403);
            }

            // Reset post status
            $post->update([
                'status' => 'processing',
                'published_at' => null,
            ]);

            // Dispatch job again
            \Log::info('Post reposted in processing--ww', $post->toArray());
            PublishPostToSocialMedia::dispatch($post);

            return $this->sendResponse([], 'Post reposted in processing', 200);

        } catch (\Exception $e) {
            return $this->sendError('Something went wrong', [], 500);
        }
    }



    public function publishToTikTok(Post $post, SocialTokenService $tokenService)
    {
        $account = auth()->user()->socialAccounts()->where('platform', 'tiktok')->first();

        try {
            $validToken = $tokenService->getValidToken($account);
            
            // Use $validToken to upload video/post to TikTok
            $response = Http::withToken($validToken)
                ->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
                    // TikTok specific payload
                ]);

        } catch (\Exception $e) {
            return $this->sendError('Failed to publish to TikTok', ['error' => $e->getMessage()], 500);
        }
    }
}
