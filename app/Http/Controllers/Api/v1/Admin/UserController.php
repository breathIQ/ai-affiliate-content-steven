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

class UserController extends ResponseController
{
    public function index(Request $request)
    {
        $role_id = Common::getRoleId('User');
        // dd($role_id);
        $query = User::query()->where('role_id',$role_id)->where('status',Config::get('constant.status.Active'))
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

    public function updateRequestStatus($id, Request $request)
    {
        try {
            // Validate the incoming request for 'status' field
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:1,2',  // 1 for accepted, 2 for rejected
            ], [
                'status.in' => 'Status should 1 for accepted, 2 for rejected.',
            ]);

            if ($validator->fails()) {
                return $this->sendValidationError($validator->errors());
            }

            // Find the user by ID
            $user = User::findOrFail($id);

            // Begin database transaction
            DB::transaction(function () use ($user, $request) {

                // Update the registration status based on the request
                $user->status = $request->status; // 1 for accepted, 2 for rejected
                $user->save();

            });

            // Send a success response
            return $this->sendResponse([], 'User registration request ' . ($request->status == 1 ? 'accepted' : 'rejected') . ' successfully.', 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Handle case if user is not found
            return $this->sendError('User not found.', [], 404);

        } catch (\Exception $e) {
            // Handle any other errors
            return $this->sendError('Failed to update user registration status: ' . $e->getMessage(), [], 500);
        }
    }

}
