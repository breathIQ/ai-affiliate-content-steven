<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Facades\Auth;

class UserDashboardController extends ResponseController
{
    public function getDashboardData(Request $request)
    {   
        $user = Auth::user();

        return $this->sendResponse([],'dashboard data get successfully', 200);
    }
}
