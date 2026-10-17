<?php

namespace App\Core\Observability\Http;

use App\Core\Observability\Health\Readiness;
use Illuminate\Http\JsonResponse;

/**
 * NFR-01: `GET /health/live` (the process answers; no dependency, for a
 * process manager) and `GET /health/ready` (every dependency answers; 503
 * otherwise, for the load balancer and uptime monitor). `/up` stays as
 * Laravel's liveness route with the sync-lag headers (NFR-04).
 */
final class HealthController
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok'])->header('Cache-Control', 'no-store');
    }

    public function ready(Readiness $readiness): JsonResponse
    {
        $result = $readiness->run();

        return response()
            ->json(['status' => $result['ready'] ? 'ok' : 'fail', 'checks' => $result['checks']], $result['ready'] ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
