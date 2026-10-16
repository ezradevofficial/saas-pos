<?php

namespace Modules\Ecommerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ECOM-07, NFR-06: the storefront API's answers are data, never pages: no
 * content of theirs may run or be framed, no Referer leaves, and answers
 * about customers and orders are never cached (catalogue answers that set
 * a public cache themselves keep it).
 */
class ShopSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        if (! str_contains((string) $response->headers->get('Cache-Control'), 'public')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
