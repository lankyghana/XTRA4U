<?php

namespace App\Http\Controllers\DeveloperApi;

use App\Http\Controllers\Controller;
use App\Support\DeveloperApi\ApiResponse;

class HealthController extends Controller
{
    public function __invoke()
    {
        return ApiResponse::success([
            'status' => 'ok',
            'version' => config('developer_api.version'),
            'time' => now()->toIso8601String(),
        ], 'API is available');
    }
}
