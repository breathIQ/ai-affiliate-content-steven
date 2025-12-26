<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Http\Request;
use App\Helpers\Common;
use App\Models\{User};
use Illuminate\Support\Facades\Auth;

class UserController extends ResponseController
{
    public function index(Request $request)
    {
        $role_id = Common::getRoleId('User');
        // dd($role_id);
        $query = User::query()->where('role_id',$role_id)
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
}
