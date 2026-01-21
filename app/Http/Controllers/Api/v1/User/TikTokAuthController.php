<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class TikTokAuthController extends ResponseController
{
    public function redirect()
    {
        [$verifier, $challenge] = $this->generatePkce();
        $state = (string) Str::uuid();

        Cache::put('tiktok_pkce_'.$state, $verifier, now()->addMinutes(10));
    
        $query = http_build_query([
            'client_key'    => Config::get('services.tiktok.client_key'),
            'response_type' => 'code',
            'scope'         => 'user.info.basic', //,video.publish
            'redirect_uri'  => Config::get('services.tiktok.redirect'),
            'state'         => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        \Log::info('TikTok Auth Redirect URL:', [$query]);
        // dd("https://www.tiktok.com/v2/auth/authorize/?{$query}");   
        return redirect()->away("https://www.tiktok.com/v2/auth/authorize/?{$query}");
    }

    public function callback(Request $request)
    {
        $verifier = Cache::pull('tiktok_pkce_'.$request->state);
        \Log::info('TikTok Auth Verifier:', $verifier);
        if (!$verifier) {
            abort(400, 'PKCE verifier missing');
        }
        $response = Http::asForm()->post(
            'https://open.tiktokapis.com/v2/oauth/token/',
            [
                'client_key'    => Config::get('services.tiktok.client_key'),
                'client_secret' => Config::get('services.tiktok.client_secret'),
                'code'          => $request->code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => Config::get('services.tiktok.redirect'),
                'code_verifier' => $verifier,
            ]
        );
        \Log::info('TikTok Auth Response:', $response->json());
        $data = $response->json();
        \Log::info('TikTok Auth Data:', $data);

        $user = Http::withToken($data['access_token'])
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => 'open_id,username,avatar_url'
            ]);
        \Log::info('TikTok Auth User:', $user->json());
        $user = $user->json();

        return $this->sendResponse($user, 'TikTok Auth successful', 200);
        // store access_token, refresh_token, open_id
    }

    private function generatePkce()
    {
        $verifier = bin2hex(random_bytes(32));

        $challenge = rtrim(strtr(
            base64_encode(hash('sha256', $verifier, true)),
            '+/',
            '-_'
        ), '=');

        return [$verifier, $challenge];
    }

}
