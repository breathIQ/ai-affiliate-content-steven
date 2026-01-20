<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use App\Http\Controllers\Api\v1\ResponseController;

class InstagramAuthController extends ResponseController
{
    public function redirect()
    {
        $query = http_build_query([
            'client_id' => Config::get('services.instagram.client_id'),
            'redirect_uri' => Config::get('services.instagram.redirect'),
            'scope' => 'instagram_basic',
            'response_type' => 'code',
        ]); 
        // dd('https://www.facebook.com/v19.0/dialog/oauth?' . $query);
        return redirect('https://www.facebook.com/v19.0/dialog/oauth?' . $query);
    }

    public function callback(Request $request)
    {
        if (!$request->code) {
            abort(400, 'Authorization failed');
        }

        $tokenResponse = Http::asForm()->post(
            'https://graph.facebook.com/v19.0/oauth/access_token',
            [
                'client_id' => Config::get('services.instagram.client_id'),
                'client_secret' => Config::get('services.instagram.client_secret'),
                'redirect_uri' => Config::get('services.instagram.redirect'),
                'code' => $request->code,
            ]
        );

        $accessToken = $tokenResponse['access_token'];

        $user = Http::get('https://graph.facebook.com/me', [
            'fields' => 'id,name,email',
            'access_token' => $accessToken,
        ]);

        // Create or login user
        $localUser = User::firstOrCreate(
            ['facebook_id' => $user['id']],
            ['name' => $user['name'], 'email' => $user['email'] ?? null]
        );

        $token = $localUser->createToken('auth')->plainTextToken;

        // return redirect(
        //     config('app.frontend_url') . '/auth-success?token=' . $token
        // );
        return response()->json([
            'token' => $token,
            'user' => $localUser,
        ]);
    }

    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === 'insta_webhook_12345') {
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Invalid verify token', 403);
    }

    public function handle(Request $request)
    {
        // Log raw payload for now
        \Log::info('Instagram Webhook:', $request->all());

        return response()->json(['status' => 'ok'], 200);
    }
}
