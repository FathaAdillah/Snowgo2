<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * API-FND-001: process is alive. Never touches dependencies.
 */
final class LivenessController
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(['status' => 'ok']);
    }
}
