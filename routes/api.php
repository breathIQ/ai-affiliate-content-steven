<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\UserMiddleware;
use App\Http\Controllers\Api\v1\Admin\{AuthController,DashboardController,UserController,AffiliateController,
FileController};
use App\Http\Controllers\Api\v1\User\{UserAuthController,UserDashboardController};


// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

//****************************Common Route **************************************** */
Route::group(['prefix' => 'v1'], function () {
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

//****************************Admin Route **************************************** */
Route::group(['prefix' => 'v1/admin'], function () {

    Route::post('/login', [AuthController::class, 'login'])->name('api.login');
   

    Route::group(['middleware' => ['auth:sanctum', AdminMiddleware::class]], function () {

        Route::get('dashboard', [DashboardController::class, 'getDashboardData']);

        //**************Auth functionaity route**************************** */
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'chnagePassword']);


        //***********User Management************************** */
        Route::apiResource('/user', UserController::class);
        Route::get('user-request', [UserController::class, 'getUserRequest']);
        Route::post('update-status/{id}', [UserController::class, 'updateUserStatus']);
        // Route::post('update-status/{id}', [UserController::class, 'updateUserActiveStatus']);

        //*****************Invite Affiliates ************************** */
        Route::post('/affiliate/invite', [AffiliateController::class, 'sendAffiliateInvite']);

        //***************file functionality************************* */
        Route::post('/file/upload', [FileController::class, 'upload']);
        Route::delete('/delete/file/{id}', [FileController::class, 'destroy']);
        Route::get('/file', [FileController::class, 'getFile']);


    });

});

//*********************************************Dealer Route *********************************************** */
Route::group(['prefix' => 'v1/user'], function () {

    Route::post('/register', [UserAuthController::class, 'register']);
    Route::post('/login', [UserAuthController::class, 'login']);
    Route::post('/social-login', [UserAuthController::class, 'socialLogin']);

    Route::group(['middleware' => ['auth:sanctum', UserMiddleware::class]], function () {

        Route::get('dashboard', [UserDashboardController::class, 'getDashboardData']);

        //**************Auth functionaity route**************************** */
        Route::post('logout', [UserAuthController::class, 'logout']);
        Route::post('change-password', [UserAuthController::class, 'chnagePassword']);

    });
});

Route::get('/login', function () {
    return response()->json([
            'success' => false,
            'responseCode' => 401,
            'message' => 'Unauthenticated. Please log in.',
            'timestamp' => now()->format('Y-m-d H:i:s'),  // Using `now()` for consistency
            'data' => []
    ], 401);
})->name('login');
