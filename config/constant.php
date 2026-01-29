<?php

return [

    'role' => [
        'Admin' => 1,
        'User' => 2,
    ],

    'status' => [
        'Active' => 1,
        'Inactive' => 0,
        'Reject' => 2,
    ],

    'claud_keys' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    'gemini_keys' => [
        'key' => env('GEMINI_API_KEY'),
    ],
    'open_ai_keys' => [
        'key' => env('OPENAI_API_KEY'),
    ],



    'instagram_user_access_token' => [
        'token' => env('INSTAGRAM_USER_ACCESS_TOKEN'),
    ],

    'frontend_url' => env('FRONTEND_URL'),
    

];
