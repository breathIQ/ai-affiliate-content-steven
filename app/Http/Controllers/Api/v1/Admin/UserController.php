<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Http\Request;
use App\Helpers\Common;
use App\Models\{User};
use Illuminate\Support\Facades\Auth;
use Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

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
                'avatar' => $user->avatar ? asset(Storage::url($user->avatar)) : null,
                'affiliate_id' => $user->affiliate_id,
                'posts_generated' => $user->posts_count,
                'posts_published' => $user->published_posts_count,
                'total_clicks' => $user->affiliate_clicks_count,
                'created_at' => $user->created_at->format('Y-m-d H:i:s'),
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
            $user_data['image'] = isset($user->avatar) ? asset(Storage::url($user->avatar)) : null;
            

            return $this->sendResponse($user_data, 'user data get successfully.', 200);
        

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            
            return $this->sendError('User not found.', [], 404);

        } catch (\Exception $e) {
            // Catch any other exceptions
            return $this->sendError('An error occurred: ' . $e->getMessage(), [], 500);
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

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            
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
