<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\UserMiddleware;
use App\Http\Controllers\Api\v1\Admin\{AuthController,DashboardController,StatsController,UserController,AffiliateController,
    FileController,ContentController,CampaignController,CampaignCopyItemController,CampaignAssetController,CampaignReviewController,
    ApiBalanceController};
use App\Http\Controllers\Api\v1\User\{UserAuthController,UserDashboardController,PostController,AffiliateClickController,
    AiPostGenerationController,TikTokAuthController,InstagramAuthController,BillingController,HeygenController,GrokVideoController,
    CampaignPostGenerationController,AutomationCampaignController};


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

    //*****************Stripe Webhook ************************** */
    Route::post('/webhooks/stripe', [BillingController::class, 'webhook']);

    //*****************Public config (safe to expose - publishable key only) */
    Route::get('/config/stripe-key', fn () => response()->json(['publishable_key' => config('services.stripe.key')]));

    //***********instagram login********** */
    Route::get('/auth/instagram/redirect', [InstagramAuthController::class, 'redirect']);
    Route::get('/auth/instagram/callback', [InstagramAuthController::class, 'handleCallback']);

    // Route::get('/auth/instagram/callback-direct', [InstagramAuthController::class, 'handleCallback']);

    //***********tiktok login********** */
    Route::get('/auth/tiktok/redirect', [TikTokAuthController::class, 'redirect']);
    Route::get('/auth/tiktok/callback', [TikTokAuthController::class, 'callback']);

    //**********Content Functionality************* */
    Route::get('/content/{type}', [ContentController::class, 'show']);

    //******************* */
    Route::post('gemini-image-generate',[AiPostGenerationController::class,'generateSlide']);
    Route::post('remove-gemini-watermark',[AiPostGenerationController::class,'removeGeminiWatermark']);

    Route::get('affiliate-clicks/{affiliate_id}',[AffiliateClickController::class,'affiliateClicks']);

    //*****************Campaign resolve (called by Vercel microsite middleware)****** */
    Route::get('campaign-resolve',[\App\Http\Controllers\CampaignRedirectController::class,'resolve']);

    Route::get('check-log',[AffiliateController::class,'checkLog']);
});

//****************************Admin Route **************************************** */
Route::group(['prefix' => 'v1/admin'], function () {

    Route::post('/login', [AuthController::class, 'login'])->name('api.login');


    Route::group(['middleware' => ['auth:sanctum', AdminMiddleware::class]], function () {

        //*****************Dashboard************************** */
        Route::get('dashboard', [DashboardController::class, 'getDashboardData']);
        Route::get('most-used-chapter', [DashboardController::class, 'getMostUsedChapter']);
        Route::get('stats', [StatsController::class, 'getStats']);
        Route::get('api-balances', [ApiBalanceController::class, 'index']);

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
        Route::delete('post/{postId}', [UserController::class, 'deletePost']);


        //***************file functionality************************* */
        Route::post('/file/upload', [FileController::class, 'upload']);
        Route::delete('/delete/file/{id}', [FileController::class, 'destroy']);
        Route::get('/file', [FileController::class, 'getFile']);


        //***************Campaign management + review************************* */
        Route::get('campaigns', [CampaignController::class, 'index']);
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show']);
        Route::match(['put', 'patch'], 'campaigns/{campaign}', [CampaignController::class, 'update']);

        Route::apiResource('campaign-copy-items', CampaignCopyItemController::class)->except(['show']);
        Route::post('campaign-copy-items/{campaign_copy_item}/approve', [CampaignCopyItemController::class, 'approve']);

        Route::get('campaigns/{campaign}/assets', [CampaignAssetController::class, 'index']);
        Route::post('campaigns/{campaign}/assets', [CampaignAssetController::class, 'store']);
        Route::post('campaigns/{campaign}/assets/{asset}/current', [CampaignAssetController::class, 'setCurrent']);
        Route::delete('campaigns/{campaign}/assets/{asset}', [CampaignAssetController::class, 'destroy']);

        Route::get('campaigns-review-queue', [CampaignReviewController::class, 'queue']);
        Route::post('campaigns-review/{post}/approve', [CampaignReviewController::class, 'approve']);
        Route::post('campaigns-review/{post}/reject', [CampaignReviewController::class, 'reject']);


    });

});

//*********************************************Dealer Route *********************************************** */
Route::group(['prefix' => 'v1/user'], function () {

    Route::post('/register', [UserAuthController::class, 'register']);
    Route::post('/login', [UserAuthController::class, 'login']);
    Route::post('/social-login', [UserAuthController::class, 'socialLogin']);
    Route::post('/social-login/{provider}', [UserAuthController::class, 'socialLoginHandler']);
    Route::get('/get-chapter', [UserDashboardController::class, 'getChapter']);
    // Route::get('/affiliate-click/{post_id}/{affiliate_id}', [AffiliateClickController::class, 'track']);

    Route::post('/save-remote-file', [AffiliateClickController::class, 'saveRemoteFile']);

    //*************social media link callback*************************** */
    Route::get('/tiktok/link/callback', [TikTokAuthController::class, 'handleTikTokLinkCallback']);
    Route::get('/instagram/link/callback', [InstagramAuthController::class, 'handleInstagramLinkCallback']);


    Route::group(['middleware' => ['auth:sanctum', UserMiddleware::class]], function () {

        //*****************Dashboard************************** */
        Route::get('dashboard', [UserDashboardController::class, 'getDashboardData']);


        //*****************Posts************************** */
        Route::apiResource('/posts', PostController::class);
        Route::post('/posts/{post}/repost', [PostController::class, 'repost']);
        Route::post('/posts/{post}/publish', [PostController::class, 'publish']);
        Route::post('/posts/{post}/media', [PostController::class, 'updateMedia']);
        Route::post('/posts/{post}/media/reorder', [PostController::class, 'reorderMedia']);
        Route::delete('/posts/{post}/media/{media}', [PostController::class, 'deleteMedia']);
        Route::post('/campaigns/posts/{post}/slides/{media}/regenerate', [CampaignPostGenerationController::class, 'regenerateSlide'])->middleware('throttle:20,1');


        //*****************Ai Post Generation************************** */
        Route::post('/generate-ai-post', [AiPostGenerationController::class, 'generateContent'])->middleware('throttle:10,1'); // Limit to 10 requests per minute;

        //*****************Campaign Post Generation (co2education/co2inhaler/co2suit/co2sauna)********* */
        Route::get('/campaigns', [CampaignPostGenerationController::class, 'index']);
        Route::post('/campaigns/generate', [CampaignPostGenerationController::class, 'generate'])->middleware('throttle:10,1');

        //*****************Automation Campaigns (scheduled auto-published sequences)********* */
        Route::get('/automations', [AutomationCampaignController::class, 'index']);
        Route::post('/automations', [AutomationCampaignController::class, 'store']);
        Route::get('/automations/{id}', [AutomationCampaignController::class, 'show']);
        Route::match(['put', 'patch'], '/automations/{id}', [AutomationCampaignController::class, 'update']);
        Route::post('/automations/{id}/status', [AutomationCampaignController::class, 'setStatus']);
        Route::delete('/automations/{id}', [AutomationCampaignController::class, 'destroy']);

        //**************Auth functionaity route**************************** */
        Route::post('logout', [UserAuthController::class, 'logout']);
        Route::post('change-password', [UserAuthController::class, 'chnagePassword']);
        Route::post('/social/account/link', [UserAuthController::class, 'link']);
        Route::get('/social/accounts', [UserAuthController::class, 'getSocialAccounts']);
        Route::get('profile', [UserAuthController::class, 'getProfile']);
        Route::post('update-profile', [UserAuthController::class, 'updateProfile']);

        //******************Ai Post Generation Route************************************ */
        // Route::post('/generate-ai-post', [AiPostGenerationController::class, 'generateContent']);

        //***********tiktok account link********** */
        Route::get('/tiktok/link', [TikTokAuthController::class, 'redirectToTikTok']);

        //***********instagram account link********** */
        Route::get('/instagram/link', [InstagramAuthController::class, 'redirectToInstagramLink']);

        //*****************Billing / Credits************************** */
        Route::get('/billing/balance', [BillingController::class, 'balance']);
        Route::post('/billing/setup-intent', [BillingController::class, 'createSetupIntent']);
        Route::post('/billing/payment-method', [BillingController::class, 'setDefaultPaymentMethod']);
        Route::post('/billing/purchase-credits', [BillingController::class, 'purchaseCredits']);
        Route::post('/billing/auto-recharge', [BillingController::class, 'updateAutoRecharge']);
        Route::get('/billing/transactions', [BillingController::class, 'transactions']);

        //*****************HeyGen Video Generation************************** */
        Route::post('/heygen/draft-script', [HeygenController::class, 'draftScript'])->middleware('throttle:10,1');
        Route::post('/heygen/generate', [HeygenController::class, 'generate'])->middleware('throttle:10,1');
        Route::get('/heygen/generations', [HeygenController::class, 'index']);
        Route::get('/heygen/generations/{id}/status', [HeygenController::class, 'status']);
        Route::get('/heygen/avatars', [HeygenController::class, 'avatars']);
        Route::post('/heygen/avatars/favorite', [HeygenController::class, 'toggleFavoriteAvatar']);

        //*****************User photo avatars (selfie -> avatar)************* */
        Route::post('/heygen/photo-avatars', [HeygenController::class, 'createPhotoAvatar'])->middleware('throttle:5,1');
        Route::get('/heygen/photo-avatars', [HeygenController::class, 'listPhotoAvatars']);
        Route::delete('/heygen/photo-avatars/{id}', [HeygenController::class, 'deletePhotoAvatar']);

        //*****************User voice clones (own voice)********************* */
        Route::post('/heygen/voice-clones', [HeygenController::class, 'createVoiceClone'])->middleware('throttle:5,1');
        Route::get('/heygen/voice-clones', [HeygenController::class, 'listVoiceClones']);
        Route::delete('/heygen/voice-clones/{id}', [HeygenController::class, 'deleteVoiceClone']);

        //*****************Grok Image-to-Video Generation************************** */
        Route::post('/grok/generate-video', [GrokVideoController::class, 'generate'])->middleware('throttle:10,1');
        Route::get('/grok/videos/{id}/status', [GrokVideoController::class, 'status']);


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
