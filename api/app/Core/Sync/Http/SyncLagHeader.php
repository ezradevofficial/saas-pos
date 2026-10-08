<?php

namespace App\Core\Sync\Http;

use App\Core\Sync\SyncLag;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-04: GET /up (the health check) carries the sync lag in
 * `X-Sync-Lag-Seconds` and `X-Sync-Lag-Xids` (SyncLag), and logs a warning
 * when it is high. Never fails the health check by itself.
 */
class SyncLagHeader
{
    public function __construct(private readonly SyncLag $lag) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('up') && $response->isSuccessful() && ($lag = $this->lag->check()) !== null) {
            $response->headers->set('X-Sync-Lag-Seconds', (string) $lag['oldest_transaction_seconds']);
            $response->headers->set('X-Sync-Lag-Xids', (string) $lag['xid_lag']);
        }

        return $response;
    }
}
