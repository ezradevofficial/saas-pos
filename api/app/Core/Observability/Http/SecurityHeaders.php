<?php

namespace App\Core\Observability\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-06: security headers on every API response.
 *
 * - `X-Content-Type-Options: nosniff`: a JSON or file response is never
 *   run as a script or style.
 * - `Referrer-Policy: no-referrer`: signed links and tokens in URLs never
 *   leak to another site (a route may set a stricter value itself).
 * - `X-Frame-Options: DENY` and CSP `frame-ancestors 'none'`: the API is
 *   never framed (clickjacking).
 * - A restrictive CSP for data responses (`default-src 'none'`): the API
 *   serves data, not pages. HTML pages, PDFs and images are left to their
 *   route (frame-ancestors still applies through X-Frame-Options).
 * - `Strict-Transport-Security` only in production and only over HTTPS,
 *   so dev and staging hosts behind plain HTTP are never pinned.
 *
 * The web app's HTML gets its own CSP from the web server (docs/security.md).
 */
final class SecurityHeaders
{
    public const API_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    public const HSTS = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');

        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'no-referrer');
        }

        // Pages (Horizon, printable documents) and files (PDF, images) keep
        // their own policy or none; everything else is data.
        $type = strtolower((string) $headers->get('Content-Type'));
        $document = str_starts_with($type, 'text/html') || str_starts_with($type, 'application/pdf') || str_starts_with($type, 'image/');

        if (! $document && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::API_CSP);
        }

        if (! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        }

        if (app()->environment('production') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', self::HSTS);
        }

        return $response;
    }
}
