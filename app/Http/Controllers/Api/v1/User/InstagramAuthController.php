<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Str;
use App\Models\{SocialAccount,User};
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use App\Helpers\Common;
use Illuminate\Support\Facades\Log;
use Exception;

class InstagramAuthController extends ResponseController
{
    // public function redirect()
    // {
    //     $query = http_build_query([
    //         'client_id' => Config::get('services.instagram.client_id'),
    //         'redirect_uri' => Config::get('services.instagram.redirect'),
    //       'scope'         => implode(',', [
    //             'instagram_business_basic',
    //             'instagram_business_content_publish',
    //             'instagram_business_manage_comments',
    //             'instagram_business_manage_insights',
    //         ]),
    //         'response_type' => 'code',
    //         'state'         => csrf_token(),
    //     ]); 
    //     \Log::info('Instagram Auth Redirect URL:', $query);
    //     // dd('https://www.facebook.com/v19.0/dialog/oauth?' . $query);
    //     return redirect('https://www.facebook.com/v19.0/dialog/oauth?' . $query);
    // }

    // public function callback(Request $request)
    // {
    //     if (!$request->code) {
    //         abort(400, 'Authorization failed');
    //     }

    //     $tokenResponse = Http::asForm()->post(
    //         'https://graph.facebook.com/v19.0/oauth/access_token',
    //         [
    //             'client_id' => Config::get('services.instagram.client_id'),
    //             'client_secret' => Config::get('services.instagram.client_secret'),
    //             'redirect_uri' => Config::get('services.instagram.redirect'),
    //             'code' => $request->code,
    //         ]
    //     );

    //     $accessToken = $tokenResponse['access_token'];
    //     \Log::info('Instagram Auth Access Token:', $accessToken);
    //     $user = Http::get('https://graph.facebook.com/me', [
    //         'fields' => 'id,name,email',
    //         'access_token' => $accessToken,
    //     ]);

    //     \Log::info('Instagram Auth User:', $user);
    //     // Create or login user
    //     $localUser = User::firstOrCreate(
    //         ['facebook_id' => $user['id']],
    //         ['name' => $user['name'], 'email' => $user['email'] ?? null]
    //     );

    //     $token = $localUser->createToken('auth')->plainTextToken;

    //     // return redirect(
    //     //     config('app.frontend_url') . '/auth-success?token=' . $token
    //     // );
    //     return response()->json([
    //         'token' => $token,
    //         'user' => $localUser,
    //     ]);
    // }

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
    
    public function redirect()
    {
        $state = (string) Str::uuid();
        $query = http_build_query([
            'enable_fb_login' => 0,
            'force_authentication' => 1,
            'client_id' => Config::get('services.instagram.client_id'),
            'redirect_uri' => Config::get('services.instagram.redirect'),
           'scope'         => implode(',', [
                'instagram_business_basic',
                'instagram_business_content_publish',
                'instagram_business_manage_comments',
                'instagram_business_manage_insights',
            ]),
            'response_type' => 'code',
            'state'         => $state,
        ]); 
        \Log::info('Instagram Auth Redirect URL:', [$query]);
        // dd('https://www.facebook.com/v19.0/dialog/oauth?' . $query);
        
        $authurl = "https://www.instagram.com/oauth/authorize/?{$query}";
        return $this->sendResponse($authurl, 'Instagram Auth url', 200);
    }
    
    public function handleCallback(Request $request) 
    {
        $code = $request->query('code');
        \Log::info('Instagram Auth Callback Code:', [$code]);
        if (!$code) {
            return redirect(Config::get('constant.frontend_url') . '/login?status=false&message=auth_failed');
        }

        // Exchange Code for Short-Lived Token
        $response = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
            'client_id' => Config::get('services.instagram.client_id'),
            'client_secret' => Config::get('services.instagram.client_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => Config::get('services.instagram.redirect'),
            'code' => $code,
        ]);
        \Log::info('response of instgram',[$response->json()]);
        $data = $response->json();
        $shortLivedToken = $data['access_token'];
        // $instagramUserId = $data['user_id'];

        // Exchange for Long-Lived Token (60 days)
        $longLivedResponse = Http::get('https://graph.instagram.com/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => Config::get('services.instagram.client_secret'),
            'access_token' => $shortLivedToken,
        ]);

        $longData = $longLivedResponse->json();
        $longLivedToken = $longData['access_token'];
        $expiresIn = $longData['expires_in'];

        // Get Profile Details (Since they aren't in the token response)
        $profileData = Http::get("https://graph.instagram.com/me", [
            'fields' => 'id,username,name,profile_picture_url',
            'access_token' => $longLivedToken,
        ])->json();

        $name = $profileData['name'] ?? $profileData['username'] ?? 'Instagram User';
        $avatar = $profileData['profile_picture_url'] ?? null;
        $instagramUserId = $profileData['id'];

        // Check if social account exists
        $social = SocialAccount::where([

            'provider' => 'instagram',

            'provider_user_id' => $instagramUserId

        ])->first();    

        if ($social) {
            $user = $social->user;

            //update access token
            $social->update([
                'access_token' =>  $longLivedToken,
                'refresh_token' => $longData['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds($expiresIn ?? 0),
            ]);
        } else {
            // Try match by email
            //$user = User::where('email', $user_data['email'])->first();

           // if (!$user) {
                $user = User::create([
                    'name' =>  $name ?? 'Test Instagram User',
                    'email' =>  NUll,
                    'avatar' =>  $this->getAvatarPath($avatar),
                    'password' => null, // social-only user
                    'status' => Config::get('constant.status.Active'),
                    'joined_by' => 'Instagram',
                    'role_id' => Common::getRoleId('User'),
                    'affiliate_id' => Common::generateUniqueAffiliateId($name ?? 'test'),
                ]);
           // }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => 'instagram',
                'provider_user_id' => $instagramUserId,
                'username' =>  $profileData['username'] ?? 'Test Instagram User',
                'access_token' => $longLivedToken,
                'refresh_token' => $longData['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds($expiresIn ?? 0),
            ]);
        }

        $token = $user->createToken('api_token')->plainTextToken;
        $user['access_token'] = $token;

        // return $this->sendResponse($user, 'TikTok Auth successful', 200);
        
        $jsonData = urlencode(json_encode($user));
        $frontendUrl = Config::get('constant.frontend_url').'/login?user=' . $jsonData;
        return redirect()->away($frontendUrl);
    }
    
    private function getAvatarPath($avatarUrl)
    {
        $avatarPath = null;

        if ($avatarUrl) {
            $response = Http::timeout(10)->get($avatarUrl);
        
            if ($response->successful()) {
                $filename = time() . '_instagram.jpg';
        
                $path = 'uploads/avatars/' . $filename;
        
                Storage::disk('public')->put($path, $response->body());
                $avatarPath = $path;
            }
        }
        return $avatarPath;
    }

    public function redirectToInstagramLink()
    {
        $user = Auth::user();
        $state = encrypt($user->id);
        $query = http_build_query([
            'enable_fb_login' => 0,
            'force_authentication' => 1,
            'client_id' => Config::get('services.instagram.client_id'),
            'redirect_uri' => Config::get('services.instagram.link_redirect'),
           'scope'         => implode(',', [
                'instagram_business_basic',
                'instagram_business_content_publish',
                'instagram_business_manage_comments',
                'instagram_business_manage_insights',
            ]),
            'response_type' => 'code',
            'state'         => $state,
        ]); 
        \Log::info('Instagram link account Redirect URL:', [$query]);
        
        $authurl = "https://www.instagram.com/oauth/authorize/?{$query}";
        return $this->sendResponse($authurl, 'Instagram link account url', 200);
    }

    public function handleInstagramLinkCallback(Request $request)
    {
        Log::info('Instagram link account Callback Request:', [$request->all()]);
        $code = $request->query('code');
        \Log::info('Instagram link account Callback Code:', [$code]);
        if (!$code) {
            return redirect(Config::get('constant.frontend_url') . '/login?status=false&message=auth_failed');
        }
        $userId = decrypt($request->state);
        Log::info('Instagram link user_id: '.$userId);
        $user = User::findOrFail($userId);
        
        // Exchange Code for Short-Lived Token
        $response = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
            'client_id' => Config::get('services.instagram.client_id'),
            'client_secret' => Config::get('services.instagram.client_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => Config::get('services.instagram.link_redirect'),
            'code' => $code,
        ]);
        \Log::info('response of instgram link account',[$response->json()]);
        $data = $response->json();
        $shortLivedToken = $data['access_token'];
        // $instagramUserId = $data['user_id'];

        // Exchange for Long-Lived Token (60 days)
        $longLivedResponse = Http::get('https://graph.instagram.com/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => Config::get('services.instagram.client_secret'),
            'access_token' => $shortLivedToken,
        ]);

        $longData = $longLivedResponse->json();
        $longLivedToken = $longData['access_token'];
        $expiresIn = $longData['expires_in'];

        // Get Profile Details (Since they aren't in the token response)
        $profileData = Http::get("https://graph.instagram.com/me", [
            'fields' => 'id,username,name',
            'access_token' => $longLivedToken,
        ])->json();

        $name = $profileData['username'] ?? $profileData['name'] ?? 'Instagram User';
        $instagramUserId = $profileData['id'];
        $alreadyLinked = SocialAccount::where('provider', 'instagram')
            ->where('provider_user_id', $instagramUserId)
            ->where('user_id', '!=', $user->id)
            ->exists();
        Log::info('Instagram link callback already linked: '.$alreadyLinked);
        if ($alreadyLinked) {
            // throw new Exception('This Instagram account is already linked to another user.');
            $linkResponse['status'] = false;
            $linkResponse['message'] = "This Instagram account is already linked to another user.";
            $jsonData = urlencode(json_encode($linkResponse));
            $frontendUrl = Config::get('constant.frontend_url').'/u/dashboard?linkResponse=' . $jsonData;
            return redirect()->away($frontendUrl);
        }

        $social = SocialAccount::updateOrCreate(
            [
                'provider' => 'instagram',
                'provider_user_id' => $instagramUserId,
            ],
            [
                'user_id' => $user->id,
                'username' =>  $name,
                'access_token' => $longLivedToken,
                'refresh_token' => $longData['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds($expiresIn ?? 0),
            ]
        );
        
        Log::info('Instagram link callback social: '.$social);
        // return $this->sendResponse($social, 'TikTok account linked successfully', 200);
        $linkResponse['status'] = True;
        $linkResponse['message'] = "Instagram account linked successfully.";
        $jsonData = urlencode(json_encode($linkResponse));
        $frontendUrl = Config::get('constant.frontend_url').'/u/dashboard?linkResponse=' . $jsonData;
        return redirect()->away($frontendUrl);
    }
}
