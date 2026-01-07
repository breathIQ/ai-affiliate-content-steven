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

];
