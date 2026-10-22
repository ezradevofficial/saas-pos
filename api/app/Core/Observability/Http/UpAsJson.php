<?php

namespace App\Core\Observability\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-01, NFR-06: Laravel's health route (/up) answers an HTML page that
 * loads fonts from a third-party CDN unless the caller asks for JSON. The
 * route stays (it is never in maintenance and carries the sync lag headers,
 * NFR-04), but it always answers `{"status": "up"|"down"}`: no page, no
 * external resource, the same for probes and browsers.
 */
class UpAsJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('up')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
