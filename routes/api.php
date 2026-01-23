<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\UserMiddleware;
use App\Http\Controllers\Api\v1\Admin\{AuthController,DashboardController,UserController,AffiliateController,
    FileController};
use App\Http\Controllers\Api\v1\User\{UserAuthController,UserDashboardController,PostController,AffiliateClickController,
    AiPostGenerationController,TikTokAuthController,InstagramAuthController};


// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

//****************************Common Route **************************************** */
Route::group(['prefix' => 'v1'], function () {
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);

    //*****************Invite Affiliates ************************** */
    Route::post('/affiliate/invite', [AffiliateController::class, 'sendAffiliateInvite']);

    //*****************Instagram Webhook ************************** */
    Route::get('/webhooks/instagram', [InstagramAuthController::class, 'verify']);
    Route::post('/webhooks/instagram', [InstagramAuthController::class, 'handle']);

    //***********instagram login********** */
    Route::get('/auth/instagram/redirect', [InstagramAuthController::class, 'redirect']);
    Route::get('/auth/instagram/callback', [InstagramAuthController::class, 'handleCallback']);
    
    // Route::get('/auth/instagram/callback-direct', [InstagramAuthController::class, 'handleCallback']);

    //***********tiktok login********** */
    Route::get('/auth/tiktok/redirect', [TikTokAuthController::class, 'redirect']);
    Route::get('/auth/tiktok/callback', [TikTokAuthController::class, 'callback']);

    

    //******************* */
    Route::post('gemini-image-generate',[AiPostGenerationController::class,'generateSlide']);
});

//****************************Admin Route **************************************** */
Route::group(['prefix' => 'v1/admin'], function () {

    Route::post('/login', [AuthController::class, 'login'])->name('api.login');
   

    Route::group(['middleware' => ['auth:sanctum', AdminMiddleware::class]], function () {

        //*****************Dashboard************************** */
        Route::get('dashboard', [DashboardController::class, 'getDashboardData']);
        Route::get('most-used-chapter', [DashboardController::class, 'getMostUsedChapter']);

        //**************Auth functionaity route**************************** */
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'chnagePassword']);
        Route::get('profile', [AuthController::class, 'getProfile']);
        Route::post('update-profile', [AuthController::class, 'updateProfile']);


        //***********User Management************************** */
        Route::apiResource('/user', UserController::class);
        Route::get('user-request', [UserController::class, 'getUserRequest']);
        Route::post('update-status/{id}', [UserController::class, 'updateUserStatus']);
        // Route::post('update-status/{id}', [UserController::class, 'updateUserActiveStatus']);
        Route::get('user/{id}/posts', [UserController::class, 'getUserPosts']);
        Route::get('post/{postId}', [UserController::class, 'getPostDetail']);


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
    Route::post('/social-login/{provider}', [UserAuthController::class, 'socialLoginHandler']);
    Route::get('/get-chapter', [UserDashboardController::class, 'getChapter']);
    Route::get('/affiliate-click/{post_id}/{affiliate_id}', [AffiliateClickController::class, 'track']);

    Route::post('/save-remote-file', [AffiliateClickController::class, 'saveRemoteFile']);
    
    Route::get('/tiktok/link/callback', [TikTokAuthController::class, 'handleTikTokLinkCallback']);


    Route::group(['middleware' => ['auth:sanctum', UserMiddleware::class]], function () {

        //*****************Dashboard************************** */
        Route::get('dashboard', [UserDashboardController::class, 'getDashboardData']);
        

        //*****************Posts************************** */
        Route::apiResource('/posts', PostController::class);

        //*****************Ai Post Generation************************** */
        Route::post('/generate-ai-post', [AiPostGenerationController::class, 'generateContent']);

        //**************Auth functionaity route**************************** */
        Route::post('logout', [UserAuthController::class, 'logout']);
        Route::post('change-password', [UserAuthController::class, 'chnagePassword']);
        Route::post('/social/account/link', [UserAuthController::class, 'link']);
        Route::get('/social/accounts', [UserAuthController::class, 'getSocialAccounts']);
        Route::get('profile', [UserAuthController::class, 'getProfile']);
        Route::post('update-profile', [UserAuthController::class, 'updateProfile']);

        //******************Ai Post Generation Route************************************ */
        Route::post('/generate-ai-post', [AiPostGenerationController::class, 'generateContent']);

        //***********tiktok account link********** */
        Route::get('/tiktok/link', [TikTokAuthController::class, 'redirectToTikTok']);
        

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
