<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Http\Request;
use App\Models\Content;
use Illuminate\Support\Facades\Log;

class ContentController extends ResponseController
{
    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:privacy_policy,terms_conditions',
            'content' => 'required|string',
        ]);

        $content = Content::updateOrCreate(
            ['type' => $request->type],
            [
                'content' => $request->content,
                'is_active' => true,
            ]
        );

        return $this->sendResponse(['content' =>$content],'Content fetched successfully',200);
    }

    
    public function show($type)
    {
        // dd('sss');
        $content = Content::where('type', $type)
            ->where('is_active', true)
            ->latest()
            ->first();

        return $this->sendResponse(['status' => true,'content' =>$content],'Content fetched successfully',200);
    }
}
