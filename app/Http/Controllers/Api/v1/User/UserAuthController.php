<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\{User,Role,SocialAccount};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Exception;
use Laravel\Socialite\Facades\Socialite;
use Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class UserAuthController extends ResponseController
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => [
                'required',
                'string',
                'min:6',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/'
            ],
        ],[
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->first());
        }
        try {
            $userRole = Role::where('role', 'User')->first();
            
            
            // Generate affiliate_id from name
            $affiliateId = $this->generateUniqueAffiliateId($request->name);
            
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role_id' => $userRole->id ?? 2,
                'affiliate_id' => $affiliateId,
                'joined_by' => 'Email',
                'status' => Config::get('constant.status.Active'),
            ]);

            $token = $user->createToken('api_token')->plainTextToken;

            $user['access_token'] = $token;
            return $this->sendResponse($user, 'Account Registered successfully', 200);

        }  catch (\Exception $err) {
            return $this->sendError('Login failed. Please try again.', [
                'error' => $err->getMessage(),
                'line' => $err->getLine(),
            ], 500);
        }
    }
    
    
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                //'email' => 'required|email:rfc,dns',   //this validation accept only original email 
                'email' => 'required|email',
                'password' => 'required|string|min:6',
            ]);
            if ($validator->fails()) {
                return $this->sendValidationError($validator->errors());
            }
            
            $user = User::where('email', $request->email)
                        ->whereHas('role', function($q) {
                            $q->where('role', 'User');
                        })->first();
            
            // Check if user exists
            if (!$user) {
                return $this->sendError('Invalid Email.', [], 401);
            }

            // Check if account is inactive
            // if ($user->status == 0 || $user->status == 2) {
            //     return $this->sendError(
            //         'Your account is inactive. Please contact admin.',
            //         [],
            //         403
            //     );
            // }
            
            // Check password
            if ($user && Hash::check($request->password, $user->password)) {
                
                $token = $user->createToken('user-token')->plainTextToken;
                // dd($token);
                $user['access_token'] = $token;
                return $this->sendResponse($user, 'Login successful', 200);
            }
            return $this->sendError('Invalid credentials', [], 401);


        }  catch (\Exception $err) {
            return $this->sendError('Login failed. Please try again.', [
                'error' => $err->getMessage(),
                'line' => $err->getLine(),
            ], 500);
        }

    }

    public function logout(Request $request)
    {
        try {
            $user = Auth::user();
           
            if ($user && $user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
                return $this->sendResponse(['role_id'=> $user->role_id], 'Logout successful', 200);
            }

            return $this->sendError('User not authenticated', [], 401);
        } catch (Exception $err) {
            return $this->sendError('An error occurred while logging out', [
                'error' => $err->getMessage(),
                'line' => $err->getLine()
            ], 500);
        }
    }


    public function chnagePassword(Request $request) {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => [
                'required',
                'string',
                'min:6',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/'
            ],
            // 'confirm_password' => 'required|same:new_password'
        ], [
            'new_password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
            'confirm_password.same' => 'Confirm password must match the new password.'
        ]);

        if($validator->fails()) {
            return $this->sendValidationError($validator->errors()->first());
        }

        try {
            $user = Auth::user();

             //  Verify current password
            if (!Hash::check($request->current_password, $user->password)) {

                 return $this->sendError('The current password is incorrect.', [], 422);
            }

            //  Update password
            $user->update([
                'password' => Hash::make($request->new_password),
            ]);


            return $this->sendResponse([], 'Password has been changed successfully !',200);
        } catch (ValidationException $e) {
           
            return $this->sendError('Validation error.', [], 422);
        } catch(Exception $err) {
            return $this->sendError('An error occurred while changing account password !', [
                'error' => $err->getMessage(),
                'line' => $err->getLine()
            ], 500);
        }
    }

    public function socialLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'provider' => 'required|in:instagram,tiktok',
            'access_token' => 'required',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }
        dd(Socialite::driver('instagram'));
        $socialUser = Socialite::driver($request->provider)
            ->stateless()
            ->userFromToken($request->access_token);

        // Check if social account exists
        $social = SocialAccount::where([
            'provider' => $request->provider,
            'provider_user_id' => $socialUser->getId()
        ])->first();    

        if ($social) {
            $user = $social->user;
        } else {
            // Try match by email
            $user = User::where('email', $socialUser->getEmail())->first();

            if (!$user) {
                $user = User::create([
                    'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                    'email' => $socialUser->getEmail(),
                    'avatar' => $socialUser->getAvatar(),
                    'password' => null, // social-only user
                    'status' => Config::get('constant.status.Active'),
                    'joined_by' => $request->provider,
                ]);
            }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $request->provider,
                'provider_user_id' => $socialUser->getId(),
                'username' => $socialUser->getNickname(),
                'access_token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'token_expires_at' => now()->addSeconds($socialUser->expiresIn ?? 0),
            ]);
        }

        $token = $user->createToken('api_token')->plainTextToken;
        $user['access_token'] = $token;
        return $this->sendResponse(['user' => $user], 'user login successfully', 200);
    }

    public function link(Request $request)
    {
       
        $validator = Validator::make($request->all(), [
            'provider' => 'required|in:instagram,tiktok',
            'access_token' => 'required',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }

        $socialUser = Socialite::driver($request->provider)
            ->stateless()
            ->userFromToken($request->access_token);

        SocialAccount::updateOrCreate(
            [
                'provider' => $request->provider,
                'provider_user_id' => $socialUser->getId(),
            ],
            [
                'user_id' => auth()->id(),
                'username' => $socialUser->getNickname(),
                'access_token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'token_expires_at' => now()->addSeconds($socialUser->expiresIn ?? 0),
            ]
        );

        return $this->sendResponse([], 'Account linked successfully', 200);
    }
    public function getProfile(Request $request)
    {
        try {
            $user = Auth::user()->load('socialAccounts');
            
            $instagram = $user->socialAccounts->where('provider', 'instagram')->first();
            $tiktok = $user->socialAccounts->where('provider', 'tiktok')->first();
            
            $data = [
                'name' => $user->name,
                'email' => $user->email,
                // 'avatar' => $user->avatar ? asset(Storage::url($user->avatar)) : null,
                 'avatar' => $user->avatar ? Config::get('constant.media_base_url').config('constant.media_base_path').$user->avatar : null,
                'affiliate_id' => $user->affiliate_id ?? '', // Assuming this exists or is username
                'affiliate_link' => 'https://co2body.com/' . ($user->affiliate_id ?? $user->username ?? $user->id), // Example format
                'other_affiliate_id' => $user->other_affiliate_id,
                'amazon_link' => $user->amazon_link,
                'affiliate_id_editable' => $user->affiliate_id_editable,
                'social_accounts' => [
                    'instagram' => [
                        'connected' => (bool)$instagram,
                        'username' => $instagram ? $instagram->username : null,
                    ],
                    'tiktok' => [
                        'connected' => (bool)$tiktok,
                        'username' => $tiktok ? $tiktok->username : null,
                        'creator_info' => $tiktok ? json_decode($tiktok->creator_info) : null,
                    ]
                ]
            ];
            
            return $this->sendResponse($data, 'User profile fetched successfully', 200);
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong', [], 500);
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            $user = Auth::user();
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',

                'affiliate_id' => 'nullable|string|max:60|unique:users,affiliate_id,' . $user->id,
                'other_affiliate_id'  => 'nullable|digits_between:1,10',

                
                // amazon field
                'amazon_link' => [
                    'nullable',
                    'url',
                    function ($attribute, $value, $fail) {
                        $url = parse_url($value);
                        $host = strtolower($url['host'] ?? '');

                        if (($url['scheme'] ?? '') !== 'https' || !preg_match('/(^|\.)amazon\.com$/', $host)) {
                            $fail('The link must be a valid HTTPS Amazon.com URL.');
                            return;
                        }

                        $path = $url['path'] ?? '';
                        $bookAsins = array_map('strtoupper', config('services.amazon.book_asins', []));

                        // The book's own product page is always acceptable;
                        // any other product page is not.
                        if (preg_match('#/(?:dp|gp/product)/([A-Z0-9]{10})#i', $path, $m)) {
                            if (!in_array(strtoupper($m[1]), $bookAsins, true)) {
                                $fail('This Amazon link points to a different product. Paste the link to your review of the book instead.');
                            }
                            return;
                        }

                        // Otherwise it must be a customer-review permalink, e.g.
                        // /gp/customer-reviews/R... or /portal/customer-reviews/srp/-/R...
                        if (!preg_match('#/customer-reviews/(?:[^/]+/)*R[A-Z0-9]{7,}#i', $path)
                            && !preg_match('#^/review/R[A-Z0-9]{7,}#i', $path)) {
                            $fail('The link must be your Amazon review of the book: open your review on Amazon and copy its link.');
                            return;
                        }

                        // Classic review permalinks carry the product ASIN in the
                        // query - when present it must be one of the book's editions.
                        parse_str($url['query'] ?? '', $query);
                        $asin = strtoupper($query['ASIN'] ?? $query['asin'] ?? '');
                        if ($asin !== '' && $bookAsins && !in_array($asin, $bookAsins, true)) {
                            $fail('This review link is for a different product, not the book.');
                        }
                    }
                ],
            ],[
                'affiliate_id.unique' => 'The affiliate Url is already in use.',
                'avatar.max' => 'The image size should not greater than 2MB',
            ]);

            if ($validator->fails()) {
                return $this->sendValidationError($validator->errors()->first());
            }

            $avatarPath = $user->avatar;
            /**
         * CASE 1: Avatar explicitly sent as empty string → remove avatar
         */
            if ($request->has('avatar') && $request->avatar == '') {

                    if ($user->avatar) {
                        $oldPath = str_replace('/storage/', '', $user->avatar);

                        if (Storage::disk('public')->exists($oldPath)) {
                            Storage::disk('public')->delete($oldPath);
                    }
                }

                $avatarPath = null;
            }
            /**
         * CASE 2: New avatar uploaded
         */
            if ($request->hasFile('avatar')) {
                // Delete old avatar if exists
                 if ($user->avatar) {
                    $oldPath = str_replace('/storage/', '', $user->avatar);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                 }

                $file = $request->file('avatar');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('uploads/avatars', $filename, 'public');
                $avatarPath =  $path;
            }

            $data['name'] = $request->name;
            $data['avatar'] = $avatarPath;
            $data['amazon_link'] = $request->amazon_link;

            if ($user->other_affiliate_id == null && $request->filled('other_affiliate_id')) {
                $data['other_affiliate_id'] = $request->other_affiliate_id;
            }

            if ($user->affiliate_id_editable == 1 && $request->filled('affiliate_id')) {
                $data['affiliate_id'] = $request->affiliate_id;
                $data['affiliate_id_editable'] = 0; // lock it
            }


            $user->update($data);

            return $this->sendResponse([], 'Profile updated successfully', 200);

        } catch (\Exception $e) {
            return $this->sendError('Something went wrong', [], 500);
        }
    }
    public function getSocialAccounts(Request $request)
    {
        try {
            $user = Auth::user()->load('socialAccounts');
            $platforms = [
                'instagram',
                'tiktok',
            ];
    
            $response = collect($platforms)->mapWithKeys(function ($platform) use ($user) {
                $account = $user->socialAccounts
                    ->firstWhere('provider', $platform);
    
                return [
                    $platform => [
                        'connected' => (bool) $account,
                        'username'  => $account?->username,
                    ]
                ];
            });
    
            return $this->sendResponse(
                $response,
                'User social accounts fetched successfully',
                200
            );
        } catch (\Exception $e) {
            return $this->sendError('Something went wrong', [], 500);
        }
    }

    private function generateUniqueAffiliateId($name)
    {
        $slug = Str::slug($name, '');
        $originalSlug = $slug;
        $count = 1;

        while (User::where('affiliate_id', $slug)->exists()) {
            $slug = $originalSlug . $count;
            $count++;
        }

        return $slug;
    }

    // public function handle(Request $request, $provider)
    // {
    //     if ($provider === 'tiktok') {
    //         return $this->tiktokLogin($request->code,$request->code_verifier);
    //     }

    //     if ($provider === 'instagram') {
    //         return $this->instagram($request->code);
    //     }

    //     return response()->json(['error' => 'Invalid provider'], 400);
    // }

    // private function tiktokLogin($code,$codeVerifier)
    // {
    //     $response = Http::asForm()->post(
    //         'https://open-api.tiktok.com/oauth/access_token/',
    //         [
    //             'client_key' => Config::get('services.tiktok.client_key'),
    //             'client_secret' => Config::get('services.tiktok.client_secret'),
    //             'code' => $code,
    //             'grant_type' => 'authorization_code',
    //             'redirect_uri' => Config::get('services.tiktok.redirect'),
    //             'code_verifier' => $codeVerifier,
    //         ]
    //     )->json();

    //     if (!isset($response['data']['open_id'])) {
    //         return response()->json($response, 400);
    //     }

    //     $user = User::updateOrCreate(
    //         ['provider' => 'tiktok', 'provider_id' => $response['data']['open_id']],
    //         ['name' => 'TikTok User']
    //     );

    //     return response()->json([
    //         'token' => $user->createToken('api')->plainTextToken,
    //         'user' => $user,
    //     ]);
    // }

    


}
