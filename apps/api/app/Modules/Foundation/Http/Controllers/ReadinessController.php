<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Health\ReadinessProbe;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * API-FND-005: required dependencies are reachable; 503 otherwise.
 */
final class ReadinessController
{
    public function __invoke(ReadinessProbe $probe): JsonResponse
    {
        $checks = $probe->run();
        $healthy = collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok');
        $data = ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks];

        if ($healthy) {
            return ApiResponse::success($data);
        }

        return ApiResponse::error('DEPENDENCY_UNAVAILABLE', 'Dependency unavailable', 503, null, ['health' => $data], ['Retry-After' => '10']);
    }
}
