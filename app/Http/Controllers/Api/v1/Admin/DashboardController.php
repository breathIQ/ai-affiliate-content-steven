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
        $auth_user = Auth::user();
        return $this->sendResponse([],'dashboard data get successfully', 200);
    }
}
