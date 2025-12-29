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

class UserAuthController extends ResponseController
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);
        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors());
        }
        try {
            $userRole = Role::where('role', 'User')->first();
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role_id' => $userRole->id ?? 2,
                'joined_by' => 'Email',
                'status' => Config::get('constant.status.Inactive'),
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
            if ($user->status == 0) {
                return $this->sendError(
                    'Your account is inactive. Please contact admin.',
                    [],
                    403
                );
            }
            
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
            return $this->sendValidationError($validator->errors());
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
        $request->validate([
            'provider' => 'required|in:instagram,tiktok',
            'access_token' => 'required',
        ]);

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
                    'status' => Config::get('constant.status.Inactive'),
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

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }
}
