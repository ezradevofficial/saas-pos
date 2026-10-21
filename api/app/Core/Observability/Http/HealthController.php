<?php

namespace App\Core\Observability\Http;

use App\Core\Observability\Health\Readiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

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
        // The load balancer, monitors and status probes share one round of
        // checks every few seconds (a storage write each time otherwise). A
        // cache that is down never fails the probe: the checks then run.
        $seconds = (int) config('observability.health.cache_seconds', 5);
        $result = null;
        try {
            $result = $seconds > 0 ? Cache::get('health:ready') : null;
        } catch (Throwable) {
            $result = null;
        }

        if (! is_array($result)) {
            $result = $readiness->run();
            try {
                if ($seconds > 0) {
                    Cache::put('health:ready', $result, $seconds);
                }
            } catch (Throwable) {
                // Answer anyway.
            }
        }

        return response()
            ->json(['status' => $result['ready'] ? 'ok' : 'fail', 'checks' => $result['checks']], $result['ready'] ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
