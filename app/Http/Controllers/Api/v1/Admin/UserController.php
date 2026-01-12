<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Http\Request;
use App\Helpers\Common;
use App\Models\{User, Post, PostPlatform, AffiliateClick};
use Illuminate\Support\Facades\Auth;
use Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class UserController extends ResponseController
{
    public function index(Request $request)
    {
        $role_id = Common::getRoleId('User');
        // dd($role_id);
        $query = User::query()->where('role_id',$role_id)
            ->withCount([
                'posts', // Total generated posts
                'posts as published_posts_count' => function ($q) {
                    $q->where('status', 'published');
                }, 
                'affiliateClicks'
            ])
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($sub) use ($search) {
                    $sub->where('email', 'like', '%' . $search . '%')
                        ->orWhere('name', 'like', '%' . $search . '%')
                        ->orWhere('affiliate_id', 'like', '%' . $search . '%');
                });
            })
            ->orderBy('id', 'desc');
            
        // Pagination
        $perPage = $request->get('per_page', 10);
        $users = $query->paginate($perPage);

        // Transform collection to match UI requirements
        $users->getCollection()->transform(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar ? asset(Storage::url($user->avatar)) : asset(Storage::url('uploads/avatars/dummy_user.png')),
                'affiliate_id' => $user->affiliate_id,
                'posts_generated' => $user->posts_count,
                'posts_published' => $user->published_posts_count,
                'total_clicks' => $user->affiliate_clicks_count,
                'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                'status' => $user->status,
            ];
        });

        return $this->sendResponse($users, 'Users list fetch successfully.', 200);
    }

    public function show(Request $request, $id)
    {
        try {
            
           $user = User::findOrFail($id);
           
            // if (!$user) {
                
            //     return $this->sendError('user not found.', [], 404);
            // }
            
            $user_data['id'] =  $user->id;
            $user_data['name'] =  $user->name;
            $user_data['affiliate_id'] =  $user->affiliate_id;
            $user_data['email'] =  $user->email;
            $user_data['status'] =  $user->status;
            $user_data['avatar'] = isset($user->avatar) ? asset(Storage::url($user->avatar)) : asset(Storage::url('uploads/avatars/dummy_user.png'));
            

            return $this->sendResponse($user_data, 'user data get successfully.', 200);
        

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            
            return $this->sendError('User not found.', [], 404);

        } catch (\Exception $e) {
            // Catch any other exceptions
            return $this->sendError('An error occurred: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Get posts of a specific user (admin).
     */
    public function getUserPosts(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $query = $user->posts()
                ->with(['chapter', 'media'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->search;
                    $q->where(function ($sub) use ($search) {
                        $sub->where('caption', 'like', "%{$search}%")
                            ->orWhere('script', 'like', "%{$search}%");
                    });
                })
                ->orderBy('created_at', 'desc');

            $perPage = $request->input('per_page', 10);
            $posts = $query->paginate($perPage);

            $data = $posts->getCollection()->map(function ($post) {
                $media = $post->media->sortBy('media_order')->first();
                $mediaUrl = $media ? asset(Storage::url($media->media_path)) : null;

                return [
                    'id' => $post->id,
                    'media' => $mediaUrl,
                    'post_content' => Str::limit($post->caption ?? $post->script, 80),
                    'chapter_name' => $post->chapter->name ?? $post->chapter->chapter_title ?? 'N/A',
                    'chapter_code' => $post->chapter->chapter ?? '',
                    'hashtags_count' => $post->hastag ? count(json_decode($post->hastag, true) ?: explode(',', $post->hastag)) : 0,
                    'ai_model' => $post->ai_model,
                    'ai_generated' => true,
                    'status' => $post->status,
                    'created_at' => $post->created_at->format('M d, Y'),
                ];
            });

            return $this->sendResponse([
                'posts' => $data,
                'pagination' => [
                    'total' => $posts->total(),
                    'current_page' => $posts->currentPage(),
                    'last_page' => $posts->lastPage(),
                    'per_page' => $posts->perPage(),
                    // 'total_pages' => $posts->lastPage(),
                ],
            ], 'User posts fetched successfully', 200);
        } catch (ModelNotFoundException $e) {
            return $this->sendError('User not found.', [], 404);
        } catch (\Exception $e) {
            return $this->sendError('Failed to fetch user posts.', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Admin view details of a specific post.
     */
    public function getPostDetail(Request $request, $postId)
    {
        try {
            $post = Post::with(['chapter', 'media', 'user'])->findOrFail($postId);

            // Format Media
            $formattedMedia = $post->media->sortBy('media_order')->map(function($m) {
                return [
                    'id' => $m->id,
                    'type' => $m->media_type,
                    'url' => asset(Storage::url($m->media_path)),
                    'order' => $m->media_order
                ];
            });

            $data = [
                'id' => $post->id,
                'user' => [
                    'id' => $post->user->id,
                    'name' => $post->user->name,
                    'email' => $post->user->email,
                    'avatar' => $post->user->avatar ? asset(Storage::url($post->user->avatar)) : asset(Storage::url('uploads/avatars/dummy_user.png')),
                ],
                'chapter' => [
                    'id' => $post->chapter_id,
                    'chapter_title' => $post->chapter->chapter_title ?? 'N/A',
                    'chapter' => $post->chapter->chapter ?? '',
                ],
                'media_assets' => $post->media_assets,
                'media' => $formattedMedia,
                'caption' => $post->caption,
                'script' => $post->script,
                //'chapter_title' => $post->chapter->chapter_title ?? 'N/A',
                //'chapter' => $post->chapter->chapter ?? '',
                'hashtags_count' => $post->hastag ? count(json_decode($post->hastag, true) ?: explode(',', $post->hastag)) : 0,
                'hashtags' => $post->hastag,
                'ai_model' => $post->ai_model,
                'ai_prompt' => $post->ai_prompt,
                'affiliate_url' => $post->affiliate_url,
                'status' => $post->status,
                'created_at' => $post->created_at->format('M d, Y'),
                // Published platforms
                'published_platforms' => $post->platforms()->where('status', 'published')->pluck('platform')->toArray(),
                // Total clicks across all platforms
                'total_clicks' => $post->total_clicks,
                // Clicks per platform
                'platform_clicks' => PostPlatform::where('post_id', $post->id)
                    ->where('status', 'published')
                    ->pluck('clicks', 'platform')
                    ->toArray(),
            ];

            return $this->sendResponse($data, 'Post details fetched successfully', 200);
        } catch (ModelNotFoundException $e) {
            return $this->sendError('Post not found.', [], 404);
        } catch (\Exception $e) {
            return $this->sendError('Failed to fetch post details.', ['error' => $e->getMessage()], 500);
        }
    }


    public function destroy($id)
    {
        try {
            $user = User::with(['socialAccounts'])->findOrFail($id);

            DB::transaction(function() use ($user) {

                // Delete related social account of users
                if ($user->socialAccounts()->exists()) {
                    $user->socialAccounts()->delete();
                }

                // Finally delete the user
                $user->delete();
            });

           
             return $this->sendResponse([],'User and related data deleted successfully.', 200);

        } catch (ModelNotFoundException $e) {
            
            return $this->sendError('User not found.', [], 404);

        } catch (\Exception $e) {
            
            return $this->sendError('Failed to delete user: ' . $e->getMessage(), [], 500);
        }
    }

    public function getUserRequest(Request $request)
    {
        $role_id = Common::getRoleId('User');
        // dd($role_id);
        $query = User::query()->where('role_id',$role_id)->where('status',Config::get('constant.status.Inactive'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($sub) use ($search) {
                    $sub->where('email', 'like', '%' . $search . '%')
                        ->orWhere('name', 'like', '%' . $search . '%')
                        ->orWhere('affiliate_id', 'like', '%' . $search . '%');
                });
            })
            ->orderBy('id', 'desc');
            
        // Pagination
        $perPage = $request->get('per_page', 10);
        $page = $request->query('page', 1);
        $users = $query->paginate($perPage);

        return $this->sendResponse($users, 'Users list fetch successfully.', 200);

    }

    public function updateUserStatus($id, Request $request)
    {
        try {
            // Validate the incoming request for 'status' field
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:0,1,2',  // 0 = inactive, 1 = accepted, 2 = rejected
            ], [
                'status.in' => 'Status should be 0 for inactive, 1 for accepted, or 2 for rejected.',
            ]);

            if ($validator->fails()) {
                return $this->sendValidationError($validator->errors());
            }

            // Find the user by ID
            $user = User::findOrFail($id);

            $user->status = $request->status;
            $user->save();

            // Status-based message
            $message = match ((int) $request->status) {
                0 => 'User account has been deactivated successfully.',
                1 => 'User registration request accepted successfully.',
                2 => 'User registration request rejected successfully.',
            };

            return $this->sendResponse([], $message, 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->sendError('User not found.', [], 404);

        } catch (\Exception $e) {
            return $this->sendError(
                'Failed to update user status: ' . $e->getMessage(),
                [],
                500
            );
        }
    }



}
