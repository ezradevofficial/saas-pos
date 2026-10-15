<?php

namespace Modules\Procurement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRC-07, PRC-23 (review): supplier portal responses never leak the link
 * token in a Referer (`Referrer-Policy: no-referrer`), are never sniffed
 * into another type, and are not cached by shared caches.
 */
class PortalSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if (! $response->headers->has('Cache-Control') || ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
