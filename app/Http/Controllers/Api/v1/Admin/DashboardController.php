<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Facades\Auth;

class DashboardController extends ResponseController
{
    public function getDashboardData(Request $request)
    {   
        // Post::where('user_id', $userId)->count();
        // Post::where('user_id', $userId)
        // ->where('status', 'published')
        // ->count();

        // Post::where('user_id', $userId)->sum('total_clicks');

        // User::withCount([
        //     'posts as posts_generated',
        //     'posts as posts_published' => fn($q) => $q->where('status', 'published')
        // ])->withSum('posts', 'total_clicks')->get();




        $auth_user = Auth::user();
        return $this->sendResponse([],'dashboard data get successfully', 200);
    }
}
