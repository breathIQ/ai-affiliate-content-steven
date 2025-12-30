<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\{StorePostRequest};
use App\Http\Controllers\Api\v1\ResponseController;
use App\Models\{PostPlatform,PostMedia,Post};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PostController extends ResponseController
{
    public function store(StorePostRequest $request)
    {
        $user = Auth::user();
        return DB::transaction(function () use ($request) {

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
                'scheduled_at'=> $request->scheduled_at ?? null,
                'published_at'=> $request->status === 'published' ? now() : null,
                'hastag' => $request->hashtags ? implode(',',$request->hashtags) : null,
            ]);

            // 2. Media (File Upload)
            if ($request->hasFile('media')) {

                foreach ($request->file('media') as $index => $file) {

                    if (!$file->isValid()) {
                        continue;
                    }

                    // Detect media type
                    $mime = $file->getMimeType();

                    $mediaType = str_starts_with($mime, 'image/')
                        ? 'image'
                        : (str_starts_with($mime, 'video/') ? 'video' : null);

                    if (!$mediaType) {
                        continue; // skip unsupported file
                    }

                    $path = $file->store('posts/media', 'public');

                    $post->media()->create([
                        'post_id' => $post->id,
                        'media_type'  => $mediaType,
                        'media_path'  => $path,
                        'media_order' => $index + 1,
                    ]);
                }
            }

            // 3. Platforms (Publish Selection)
            if ($request->filled('platforms')) {
                foreach ($request->platforms as $platform) {
                    PostPlatform::create([
                        'post_id' => $post->id,
                        'platform' => $platform,
                        'status' => 'pending'
                    ]);
                }
            }

            return $this->sendResponse($post, 'Post created successfully', 201);
            
        });
    }
}
