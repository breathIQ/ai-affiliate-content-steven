<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Facades\Auth;
use App\Models\{Chapter};


class UserDashboardController extends ResponseController
{
    public function getDashboardData(Request $request)
    {   
        $user = Auth::user();

        return $this->sendResponse([],'dashboard data get successfully', 200);
    }

    public function getChapter(Request $request)
    {
        try{

            $chapters = Chapter::select('id','book_id','chapter','chapter_title')->get();
            if(!$chapters){
                return $this->sendError('Chapters data not found.', [], 500);
            }

            return $this->sendResponse($chapters, 'chapters get successfully.', 200);

        } catch (\Exception $e) {
            return $this->sendError('chapter get failed.', ['error' => $e->getMessage()], 500);
        }
    }
}
