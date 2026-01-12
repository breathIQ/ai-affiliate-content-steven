<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ResponseController extends Controller
{
    public function sendResponse($data, $message, $code = 200)
    {
        $response = [
            'success' => true,
            'responseCode' => $code,
            'message' => $message,
            'timestamp' => now()->format('Y-m-d H:i:s'),  // Using Laravel's `now()` helper for timestamp
            'data' => $data
        ];
        return response()->json($response, $code); // Added the status code to the response
    }

    public function sendError($errorMessages = 'Something went wrong. Please try again!', $data = [], $code = 400)
    {
        $response = [
            'success' => false,
            'responseCode' => $code,
            'message' => $errorMessages,
            'timestamp' => now()->format('Y-m-d H:i:s'),  // Using `now()` for consistency
            'data' => $data
        ];

        return response()->json($response, $code); // Added the status code to the response
    }

    public function sendValidationError($errors)
    {
        $response = [
            'success' => false,
            'responseCode' => 422,
            'error' => 'Validation failed.',
            'message' => $errors,
            'timestamp' => now()->format('Y-m-d H:i:s')
        ];

        return response()->json($response, 422);
    }
}
