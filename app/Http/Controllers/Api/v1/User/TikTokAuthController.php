<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use App\Http\Controllers\Api\v1\ResponseController;

class TikTokAuthController extends ResponseController
{
    public function redirect()
    {
        $query = http_build_query([
            'client_key'    => Config::get('services.tiktok.client_key'),
            'response_type' => 'code',
            'scope'         => 'user.info.basic', //,video.publish
            'redirect_uri'  => Config::get('services.tiktok.redirect'),
            'state'         => csrf_token(),
        ]);
        dd("https://www.tiktok.com/v2/auth/authorize/?{$query}");   
        return redirect()->away("https://www.tiktok.com/v2/auth/authorize/?{$query}");
    }

    public function callback(Request $request)
    {
        $response = Http::asForm()->post(
            'https://open.tiktokapis.com/v2/oauth/token/',
            [
                'client_key'    => Config::get('services.tiktok.client_key'),
                'client_secret' => Config::get('services.tiktok.client_secret'),
                'code'          => $request->code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => Config::get('services.tiktok.redirect'),
            ]
        );

        $data = $response->json();

        $user = Http::withToken($data['access_token'])
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => 'open_id,username,avatar_url'
            ]);

        $user = $user->json();

        return $this->sendResponse($user, 'TikTok Auth successful', 200);
        // store access_token, refresh_token, open_id
    }


}
