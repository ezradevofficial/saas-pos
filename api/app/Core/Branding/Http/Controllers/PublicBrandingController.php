<?php

namespace App\Core\Branding\Http\Controllers;

use App\Core\Branding\Http\Requests\PublicHostRequest;
use App\Core\Branding\PublicBranding;
use App\Core\Notifications\Sms\SmsAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * BR-04: GET public/branding?host= — the sign-in page's branding for the
 * host it is served on (`data: null` for the platform default), and the
 * platform's public settings (`meta`).
 * BR-05: GET tls/ask?domain= — Caddy's on-demand TLS asks before getting a
 * certificate: 200 only for a verified custom domain of an active tenant,
 * else 404. Both are public and rate-limited per address.
 */
class PublicBrandingController
{
    public function __construct(private readonly PublicBranding $branding) {}

    /**
     * `meta.sms_available` (AUTH-01..AUTH-05): whether text messages can be
     * sent (SmsAvailability), so the web app, storefront and POS hide the
     * phone options in email-only mode. The same for every host.
     */
    public function show(PublicHostRequest $request, SmsAvailability $sms): JsonResponse
    {
        return response()->json([
            'data' => $this->branding->forHost($request->validated('host')),
            'meta' => ['sms_available' => $sms->available()],
        ])->header('Cache-Control', 'public, max-age=60');
    }

    public function tlsAsk(PublicHostRequest $request): Response
    {
        $ok = $this->branding->isVerifiedDomain($request->validated('domain'));

        return response($ok ? 'OK' : 'Not found', $ok ? 200 : 404, ['Content-Type' => 'text/plain']);
    }
}
