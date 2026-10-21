<?php

namespace App\Core\Observability\Http;

use App\Core\Observability\Metrics\HttpMetrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-02, NFR-10: counts every request and times it, by route pattern
 * (`api/v1/items/{item}`, or `unmatched`), after the response is sent.
 * The scrape itself and the health probes are not counted.
 */
final class RecordMetrics
{
    private const SKIP = ['metrics', 'up', 'health/live', 'health/ready'];

    public function __construct(private readonly HttpMetrics $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('metrics.started', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = $request->attributes->get('metrics.started');

        if (! is_float($started) || in_array(trim($request->path(), '/'), self::SKIP, true)) {
            return;
        }

        $route = $request->route();

        $this->metrics->record(
            $request->getMethod(),
            $route instanceof Route ? $route->uri() : 'unmatched',
            $response->getStatusCode(),
            microtime(true) - $started,
        );
    }
}
