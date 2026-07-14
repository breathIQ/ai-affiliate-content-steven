<?php

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Api\v1\ResponseController;
use App\Services\ProviderBalanceService;
use Illuminate\Http\Request;

class ApiBalanceController extends ResponseController
{
    public function index(Request $request, ProviderBalanceService $service)
    {
        $data = $service->all($request->boolean('fresh'));

        return $this->sendResponse($data, 'api balances fetched successfully', 200);
    }
}
