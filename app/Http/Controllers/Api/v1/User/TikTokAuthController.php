<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use App\Models\{SocialAccount,User};
use Illuminate\Support\Facades\Storage;
use App\Helpers\Common;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;



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
            'scope'         => implode(',', [
                'user.info.basic',
                'video.upload',
                'video.publish',
            ]),  //'user.info.basic', //,video.publish
            'redirect_uri'  => Config::get('services.tiktok.redirect'),
            'state'         => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        \Log::info('TikTok Auth Redirect URL:', [$query]);
        // dd("https://www.tiktok.com/v2/auth/authorize/?{$query}");   
        // return redirect()->away("https://www.tiktok.com/v2/auth/authorize/?{$query}");
        
        $authurl = "https://www.tiktok.com/v2/auth/authorize/?{$query}";
        return $this->sendResponse($authurl, 'TikTok Auth url', 200);
    }

    public function callback(Request $request)
    {
        \Log::info('TikTok Auth Callback:', [$request->all()]);
        $verifier = Cache::pull('tiktok_pkce_'.$request->state);
        \Log::info('TikTok Auth Verifier:', [$verifier]);
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
        \Log::info('TikTok Auth Data:', [$data]);
        \Log::info('access_token:'.$data['access_token']);
        $userResponse =  Http::withHeaders([
            'Authorization' => 'Bearer '.$data['access_token'],
        ])->get('https://open.tiktokapis.com/v2/user/info/', [
            'fields' => 'open_id,display_name,avatar_url',
        ]);
        \Log::info('TikTok Auth User:', $userResponse->json());
        $user_data = $userResponse->json();

        // Check if social account exists
        $social = SocialAccount::where([
            'provider' => 'tiktok',
            'provider_user_id' => $data['open_id']
        ])->first();    

        if ($social) {
            $user = $social->user;

            //update access token
            $social->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 0),
            ]);
        } else {
            // Try match by email
            //$user = User::where('email', $user_data['email'])->first();

           // if (!$user) {
                $user = User::create([
                    'name' =>  $user_data['data']['user']['display_name'] ?? NULL,
                    'email' =>  $user_data['data']['user']['email'] ?? NUll,
                    'avatar' =>  $this->getAvatarPath($user_data['data']['user']['avatar_url'] ?? null),
                    'password' => null, // social-only user
                    'status' => Config::get('constant.status.Active'),
                    'joined_by' => 'TikTok',
                    'role_id' => Common::getRoleId('User'),
                    'affiliate_id' => Common::generateUniqueAffiliateId($user_data['data']['user']['display_name'] ?? null),
                ]);
           // }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => 'tiktok',
                'provider_user_id' => $data['open_id'],
                'username' =>  $user_data['data']['user']['display_name'] ?? NULL,
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 0),
            ]);
        }

        $token = $user->createToken('api_token')->plainTextToken;
        $user['access_token'] = $token;

        // return $this->sendResponse($user, 'TikTok Auth successful', 200);
        
        $jsonData = urlencode(json_encode($user));
        $frontendUrl = Config::get('constant.frontend_url').'/login?user=' . $jsonData;
        return redirect()->away($frontendUrl);
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
    
    private function getAvatarPath($avatarUrl)
    {
        $avatarPath = null;

        if ($avatarUrl) {
            $response = Http::timeout(10)->get($avatarUrl);
        
            if ($response->successful()) {
                $filename = time() . '_tiktok.jpg';
        
                $path = 'uploads/avatars/' . $filename;
        
                Storage::disk('public')->put($path, $response->body());
                $avatarPath = $path;
            }
        }
        return $avatarPath;
    }

    public function redirectToTikTok(Request $request)
    {
        $user = Auth::user();
        $state = encrypt($user->id);
        [$verifier, $challenge] = $this->generatePkce();
    //   Cache::put(
    //         'tiktok_code_verifier_'.$state,
    //         $verifier,
    //         now()->addMinutes(10)
    //     );
        Cache::put('tiktok_code_verifier_'.$state, $verifier, now()->addMinutes(10));

        $query = http_build_query([
            'client_key' => Config::get('services.tiktok.client_key'),
            'response_type' => 'code',
            'scope'         => implode(',', [
                'user.info.basic',
                'video.upload',
                'video.publish',
            ]),
            'redirect_uri' => Config::get('services.tiktok.link_redirect'),
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state, // IMPORTANT
        ]);
        Log::info('TikTok Auth link url: '.$query);
        // return redirect('https://www.tiktok.com/v2/auth/authorize?'.$query);
        $authurl = "https://www.tiktok.com/v2/auth/authorize/?{$query}";
        return $this->sendResponse($authurl, 'TikTok Auth link url', 200);
    }

    public function handleTikTokLinkCallback(Request $request)
    {
        if ($request->error) {
            // throw new Exception($request->error_description ?? 'TikTok link failed');
            $linkResponse['status'] = false;
            $linkResponse['message'] = "TikTok link failed.";
            $jsonData = urlencode(json_encode($linkResponse));
            $frontendUrl = Config::get('constant.frontend_url').'/u/dashboard?linkResponse=' . $jsonData;
            return redirect()->away($frontendUrl);
        }

        $userId = decrypt($request->state);
        Log::info('TikTok link user_id: '.$userId);
        $user = User::findOrFail($userId);
        Log::info('TikTok link callback request: ',[$request->all()]);
        $codeVerifier = Cache::pull('tiktok_code_verifier_'.$request->state);
         Log::info('TikTok link callback request codeverifier: '.$codeVerifier);
        if (!$codeVerifier) {
            throw new Exception('Invalid or expired TikTok session');
        }

        $tokenResponse = Http::asForm()->post(
            'https://open.tiktokapis.com/v2/oauth/token/',
            [
                'client_key' => Config::get('services.tiktok.client_key'),
                'client_secret' => Config::get('services.tiktok.client_secret'),
                'code' => $request->code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => Config::get('services.tiktok.link_redirect'),
                'code_verifier' => $codeVerifier,
            ]
        )->json();
        
        Log::info('TikTok link callback token response: ',[$tokenResponse]);
        $userInfo = Http::withHeaders([
            'Authorization' => 'Bearer '.$tokenResponse['access_token'],
        ])->get('https://open.tiktokapis.com/v2/user/info/', [
            'fields' => 'open_id,display_name,avatar_url',
        ])->json();
        
        Log::info('TikTok link callback user info: ',[$userInfo]);
        $tiktokUser = $userInfo['data']['user'];
        $openId = $tiktokUser['open_id'];
    
        $alreadyLinked = SocialAccount::where('provider', 'tiktok')
            ->where('provider_user_id', $openId)
            ->where('user_id', '!=', $user->id)
            ->exists();
        Log::info('TikTok link callback already linked: '.$alreadyLinked);
        if ($alreadyLinked) {
            // throw new Exception('This TikTok account is already linked to another user.');
            $linkResponse['status'] = false;
            $linkResponse['message'] = "This TikTok account is already linked to another user.";
            $jsonData = urlencode(json_encode($linkResponse));
            $frontendUrl = Config::get('constant.frontend_url').'/u/dashboard?linkResponse=' . $jsonData;
            return redirect()->away($frontendUrl);
        }

        $social = SocialAccount::updateOrCreate(
            [
                'provider' => 'tiktok',
                'provider_user_id' => $openId,
            ],
            [
                'user_id' => $user->id,
                'username' =>  $tiktokUser['display_name'] ?? NULL,
                'access_token' => $tokenResponse['access_token'],
                'refresh_token' => $tokenResponse['refresh_token'],
                'token_expires_at' => now()->addSeconds($tokenResponse['expires_in'] ?? 0),
            ]
        );
        
        Log::info('TikTok link callback social: '.$social);
        // return $this->sendResponse($social, 'TikTok account linked successfully', 200);
        $linkResponse['status'] = True;
        $linkResponse['message'] = "TikTok account linked successfully.";
        $jsonData = urlencode(json_encode($linkResponse));
        $frontendUrl = Config::get('constant.frontend_url').'/u/dashboard?linkResponse=' . $jsonData;
        return redirect()->away($frontendUrl);
    }



}
