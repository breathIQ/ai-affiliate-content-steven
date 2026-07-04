<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\VerificationCodeMail;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Config;

class AuthController extends ResponseController
{
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
                            $q->where('role', 'Admin');
                        })->first();
            
            if (!$user || !Hash::check($request->password, $user->password)) {
                // throw ValidationException::withMessages([
                //     'email' => ['The provided credentials are incorrect.'],
                // ]);
                return $this->sendError('Invalid Email or Password.', [], 500);
            }
    
            if ($user && Hash::check($request->password, $user->password)) {
                // Auth::login($user); // Login manually
                $token = $user->createToken('admin-token')->plainTextToken;
                // dd($token);
                $user['access_token'] = $token;
                return $this->sendResponse($user, 'Login successful', 200);
            }
            return $this->sendError('Invalid credentials', [], 401);


        } catch (\Exception $e) {
            return $this->sendError('Login failed. Please try again.', [], 500);
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

    public function forgotPassword(Request $request)
    {
        try {
            // Validate email
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ], [
                'email.exists' => 'This email is not registered with us.',
            ]);

            if ($validator->fails()) {
                return $this->sendValidationError($validator->errors());
            }

            // Generate a unique verification code
            $verificationCode = random_int(100000, 999999);  // Numeric 6-digit verification code

            // Store verification code in the database or cache with expiration
            
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->email],
                [
                    'token' => $verificationCode,
                    'created_at' => now(),
                    'expires_at' => now()->addMinutes(15)
                ]
            );

            // Send verification code via email (You can create a mailable for this)
            Mail::to($request->email)->send(new VerificationCodeMail($verificationCode));

            // Return success response
            return $this->sendResponse(['email'=>$request->email], 'Verification code sent successfully!', 200);

        } catch (ValidationException $e) {
            // Laravel validation exception
            return $this->sendError('Validation failed', [
                'error' => $e->errors(),
            ], 422);

        } catch (Exception $err) {
            // Catch any unexpected error
            return $this->sendError('Something went wrong, please try again later.', [
                'error' => $err->getMessage(),
                'line' => $err->getLine()
            ], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            //  Validate input
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'token' => 'required|string',
                'password' => 'required|min:6|confirmed', // requires password_confirmation field
            ], [
                'email.exists' => 'This email is not registered with us.',
                'password.confirmed' => 'Password and Confirm Password do not match.',
            ]);

            if ($validator->fails()) {
                return $this->sendValidationError($validator->errors());
            }

            //  Find token record
            $tokenData = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (!$tokenData) {
                return $this->sendError('Invalid or expired token.', [], 400);
            }

            //  Check token match and expiry
            if ($tokenData->token !== $request->token) {
                return $this->sendError('Invalid verification code.', [], 400);
            }

            // Optional: check expiry (if you added expires_at column)
            if (isset($tokenData->expires_at) && now()->greaterThan($tokenData->expires_at)) {
                return $this->sendError('This verification code has expired.', [], 400);
            }

            //  Update user password
            User::where('email', $request->email)->update([
                'password' => Hash::make($request->password),
            ]);
            $user = User::where('email', $request->email)->first();
            $update =  $user->update([
                    'password' => Hash::make($request->password),
                ]);
            

            //  Delete used token
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            // Return success response
            return $this->sendResponse(['role_id'=>$user->role_id], 'Password reset successfully!', 200);

        } catch (\Exception $err) {
            return $this->sendError('Something went wrong, please try again later.', [
                'error' => $err->getMessage(),
                'line' => $err->getLine(),
            ], 500);
        }
    }
    public function getProfile(Request $request)
    {
        try {
            $user = Auth::user();
            // $user->avatar = isset($user->avatar) ? asset(Storage::url($user->avatar)) : null;
            $user->avatar = isset($user->avatar) ?  Config::get('constant.media_base_url').config('constant.media_base_path').$user->avatar : null; 
            return $this->sendResponse($user, 'User profile fetched successfully', 200);
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
            ]);

            if ($validator->fails()) {
                return $this->sendValidationError($validator->errors()->first());
            }

            $avatarPath = $user->avatar;
            /**
             * CASE 1: Avatar explicitly sent as empty string → remove avatar
             */
            // dd($request->has('avatar'));
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
                if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                    Storage::disk('public')->delete($user->avatar);
                }

                $file = $request->file('avatar');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('uploads/avatars', $filename, 'public');
                $avatarPath = $path; // Store relative path in DB
            }

            $user->update([
                'name' => $request->name,
                'avatar' => $avatarPath,
            ]);

            return $this->sendResponse([], 'Profile updated successfully', 200);

        } catch (\Exception $e) {
            return $this->sendError('Something went wrong', [], 500);
        }
    }
}
