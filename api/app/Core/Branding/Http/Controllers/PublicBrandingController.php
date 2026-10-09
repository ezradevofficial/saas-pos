<?php

namespace App\Core\Branding\Http\Controllers;

use App\Core\Branding\Http\Requests\PublicHostRequest;
use App\Core\Branding\PublicBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * BR-04: GET public/branding?host= — the sign-in page's branding for the
 * host it is served on (`data: null` for the platform default).
 * BR-05: GET tls/ask?domain= — Caddy's on-demand TLS asks before getting a
 * certificate: 200 only for a verified custom domain of an active tenant,
 * else 404. Both are public and rate-limited per address.
 */
class PublicBrandingController
{
    public function __construct(private readonly PublicBranding $branding) {}

    public function show(PublicHostRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->branding->forHost($request->validated('host'))])
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function tlsAsk(PublicHostRequest $request): Response
    {
        $ok = $this->branding->isVerifiedDomain($request->validated('domain'));

        return response($ok ? 'OK' : 'Not found', $ok ? 200 : 404, ['Content-Type' => 'text/plain']);
    }
}
