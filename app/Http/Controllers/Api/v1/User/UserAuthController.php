<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\{User,Role};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\v1\ResponseController;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Exception;

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
            
            if (!$user || !Hash::check($request->password, $user->password)) {
                // throw ValidationException::withMessages([
                //     'email' => ['The provided credentials are incorrect.'],
                // ]);
                return $this->sendError('Invalid Email or Password.', [], 500);
            }
    
            if ($user && Hash::check($request->password, $user->password)) {
                // Auth::login($user); // Login manually
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
}
