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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Facades\Config;


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
                $mediaUrl = $media ? Config::get('constant.frontend_url').'/storage/'.$media->media_path : null;
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
                    'url' => Config::get('constant.frontend_url').'/storage/'.$m->media_path,
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

            // 1. Create Post
            $post = Post::create([
                'user_id'      => $user->id,
                'chapter_id'   => $request->chapter_id,
                'caption'      => $request->caption,
                // 'script'       => $request->script,
                'media_assets'    => $request->media_assets,
                'status'       => 'processing',
                'ai_model'     => $request->ai_model,
                'ai_prompt'     => $request->ai_prompt,
                // 'published_at'=> $request->status === 'published' ? now() : null,
                 'published_at'=> null,
                'hastag' => $request->hashtags,
                'affiliate_url' => $user->amazon_link,
                'chapter_name' => $chapterName->chapter,
                'chapter_title' => $chapterName->chapter_title,
            ]);

            // 2. Media (File Upload)
            if ($request->input('media')) {
                foreach ($request->input('media') as $index => $mediaItem) {

                    $mediaType = null;
                    $path = null;

                    // $file = $mediaItem['file'] ?? null;
                    $uploadedFile = $request->file("media.$index.file");
                    // Check if this file exists in the request
                    if ($uploadedFile instanceof UploadedFile) {

                        // $uploadedFile = $file;

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
                        \Log::info('file path--'.$path);
                    //    dd($path) ;
                    }elseif (filter_var($mediaItem['file'], FILTER_VALIDATE_URL)) {

                        try {
                            $response = Http::timeout(30)->get($mediaItem['file']);

                            if (!$response->successful()) {
                                continue;
                            }

                            $mime = $response->header('Content-Type');

                            $mediaType = str_starts_with($mime, 'image/')
                                ? 'image'
                                : (str_starts_with($mime, 'video/') ? 'video' : null);

                            if (!$mediaType) {
                                continue;
                            }

                            // Extension from mime
                            $extension = match ($mime) {
                                'image/png' => 'png',
                                'image/jpeg' => 'jpg',
                                // 'image/webp' => 'webp',
                                'video/mp4' => 'mp4',
                                default => 'jpg',
                            };

                            $filename = Str::uuid() . '.' . $extension;
                            $path = "posts/media/$filename";
                            // dd($path);
                            \Log::info('Url path--'.$path);
                            // Use streaming for safety
                            Storage::disk('public')->writeStream(
                                $path,
                                fopen($mediaItem['file'], 'r')
                            );
                            
                            $tempUrl = $mediaItem['file'];
                            if (str_contains($tempUrl, 'posts/temp/')) {
                                // Get everything after 'storage/' to get the disk path
                                $tempPath = 'posts/temp/' . basename($tempUrl);
                                
                                if (Storage::disk('public')->exists($tempPath)) {
                                    Storage::disk('public')->delete($tempPath);
                                    \Log::info('Deleted temporary file: ' . $tempPath);
                                }
                            }
                            

                        } catch (\Throwable $e) {
                            \Log::error('Failed to download media: ' . $e->getMessage());
                            continue; // skip failed downloads
                        }
                    }elseif (is_string($mediaItem['file']) && str_starts_with($mediaItem['file'], 'data:')) {
                        
                        preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.*)$/', $mediaItem['file'], $matches);

                        if (count($matches) !== 3) {
                            \Log::error('Invalid base64 data: ' . $mediaItem['file']);
                            continue;
                        }

                        $mime = $matches[1];
                        $base64Data = preg_replace('/\s+/', '', $matches[2]);

                        $mediaType = str_starts_with($mime, 'image/')
                            ? 'image'
                            : (str_starts_with($mime, 'video/') ? 'video' : null);

                        if (!$mediaType) {
                            \Log::error('Unsupported media type: ' . $mime);
                            continue;
                        }

                        $extension = match ($mime) {
                            'image/png' => 'png',
                            'image/jpeg' => 'jpg',
                            // 'image/webp' => 'webp',
                            default => 'jpg',
                        };

                        if (!$extension) {
                            \Log::error("Unsupported mime type: $mime");
                            continue;
                        }
                        $binaryData = base64_decode($base64Data, true);

                        if ($binaryData === false) {
                            \Log::error('Base64 decode failed for mediaItem');
                            continue;
                        }

                        $filename = Str::uuid() . '.' . $extension;
                        // $filename = Str::uuid() .'.jpg';
                        $path = "posts/media/$filename";
                        
                        // Re-encode to a standard JPEG (Meta-safe)
                        // $img = Image::read($binaryData)->encodeByExtension('jpg', quality: 90);


                        Storage::disk('public')->put($path, $binaryData);
                        
                        // Storage::disk('public')->put($path, (string) $img);
                        
                        if (!Storage::disk('public')->exists($path)) {
                            \Log::error("Failed to store base64 image at $path");
                            continue;
                        }
                    }
                    \Log::info('path--'.$path.'---mediaType--'.$mediaType);
                    if ($path && $mediaType) {
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
                        'status' => 'processing'   
                    ]);
                }
            }

            // Dispatch the Job to the background
            PublishPostToSocialMedia::dispatch($post);
            
            return $this->sendResponse($post, 'Post created successfully and publishing is in progress', 201);
            
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
