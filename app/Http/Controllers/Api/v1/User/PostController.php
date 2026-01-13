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
use App\Jobs\PublishPostToSocialMedia;

class PostController extends ResponseController
{
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
                        $sub->where('caption', 'like', "%{$search}%")
                            ->orWhere('script', 'like', "%{$search}%");
                    });
                })
                ->orderBy('created_at', 'desc');

            $posts = $query->paginate($limit);

            $data = $posts->getCollection()->map(function ($post) {
                // Get primary media
                $media = $post->media->sortBy('media_order')->first();
                $mediaUrl = $media ? asset(Storage::url($media->media_path)) : null;

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
                    'post_content' => Str::limit($post->caption ?? $post->script, 80),
                    'chapter_name' => $post->chapter?->name ?? $post->chapter?->chapter_title ?? $post->chapter_title,
                    'chapter_code' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $post->chapter?->chapter) ?? preg_replace('/^CHAPTER\s+/i', 'Ch-', $post->chapter_title), // e.g. Ch-12
                    'hashtags_count' => $hashtagsCount,
                    'ai_model' => $post->ai_model,
                    'ai_generated' => true,
                    'status' => $post->status,
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
                    'type' => $m->media_type,
                    'url' => asset(Storage::url($m->media_path)),
                    'order' => $m->media_order
                ];
            });

            // Published platforms
            $published_platforms = $post->platforms()->where('status', 'published')->pluck('platform')->toArray();
            // Total clicks across all platforms
            $total_clicks = $post->total_clicks;
            // Clicks per platform
            $platform_clicks = PostPlatform::where('post_id', $post->id)
                ->where('status', 'published')
                ->pluck('clicks', 'platform')
                ->toArray();

            $data = [
                'id' => $post->id,
                'chapter' => [
                    'id' => $post->chapter_id,
                    'chapter_title' => $post->chapter?->chapter_title ?? $post->chapter_title,
                    'chapter' => preg_replace('/^CHAPTER\s+/i', 'Ch-', $post->chapter?->chapter) ?? preg_replace('/^CHAPTER\s+/i', 'Ch-', $post->chapter_name),
                ],
                'caption' => $post->caption,
                'script' => $post->script,
                'hashtags' => $post->hastag,
                'hashtags_count' => $post->hastag ? count(json_decode($post->hastag, true) ?: explode(',', $post->hastag)) : 0,
                'ai_model' => $post->ai_model,
                'ai_prompt' => $post->ai_prompt,
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
        $chapterName = Chapter::find($request->chapter_id);
        return DB::transaction(function () use ($request,$user,$chapterName) {

            // 1. Create Post
            $post = Post::create([
                'user_id'      => $user->id,
                'chapter_id'   => $request->chapter_id,
                'caption'      => $request->caption,
                'script'       => $request->script,
                'media_assets'    => $request->media_assets,
                'status'       => $request->status,
                'ai_model'     => $request->ai_model,
                'ai_prompt'     => $request->ai_prompt,
                // 'scheduled_at'=> $request->scheduled_at ?? null,
                'published_at'=> $request->status === 'published' ? now() : null,
                'hastag' => $request->hashtags,
                'affiliate_url' => $request->affiliate_url,
                'chapter_name' => $chapterName->chapter,
                'chapter_title' => $chapterName->chapter_title,
            ]);

            // 2. Media (File Upload)
            if ($request->input('media')) {
                foreach ($request->input('media') as $index => $mediaItem) {

                    // Check if this file exists in the request
                    if ($request->hasFile("media.$index.file")) {

                        $uploadedFile = $request->file("media.$index.file");

                        if (!$uploadedFile->isValid()) {
                            continue; // skip invalid files
                        }

                        // Detect media type
                        $mime = $uploadedFile->getMimeType();
                        $mediaType = str_starts_with($mime, 'image/')
                            ? 'image'
                            : (str_starts_with($mime, 'video/') ? 'video' : null);

                        if (!$mediaType) {
                            continue; // skip unsupported files
                        }

                        // Store file
                        $path = $uploadedFile->store('posts/media', 'public');

                        // Create media record
                        $post->media()->create([
                            'media_type'  => $mediaType,
                            'media_path'  => $path,
                            'media_order' => $mediaItem['media_order'] ?? 0,
                        ]);
                    }
                }
            }


            // 3. Platforms (Publish Selection)
            if ($request->filled('platforms')) {
                foreach ($request->platforms as $platform) {
                    PostPlatform::create([
                        'post_id' => $post->id,
                        'platform' => $platform,
                        'status' => 'published'   // need to change this to pending
                    ]);
                }
            }

            // Dispatch the Job to the background
            PublishPostToSocialMedia::dispatch($post);
            
            return $this->sendResponse($post, 'Post created successfully', 201);
            
        });
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
