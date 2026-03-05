<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\User\AffiliateClickController;

Route::get('/', function () {
    return view('welcome');
});


Route::get('al/{post_id}/{affiliate_id}', [AffiliateClickController::class, 'track']);