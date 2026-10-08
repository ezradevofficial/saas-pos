<?php

namespace App\Core\Approvals\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * APR-08 (L7): responses on the emailed-link endpoints carry
 * `Referrer-Policy: no-referrer`, errors included, so a token in the URL
 * never leaks to another site through the Referer header.
 */
class NoReferrer
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
