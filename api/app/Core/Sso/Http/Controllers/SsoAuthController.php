<?php

namespace App\Core\Sso\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Identity\Http\Responses\TokenResponse;
use App\Core\Identity\Services\SignInResult;
use App\Core\Sso\Http\Requests\CompleteSsoRequest;
use App\Core\Sso\Http\Requests\SsoCallbackRequest;
use App\Core\Sso\Http\Requests\StartSsoRequest;
use App\Core\Sso\SsoFlows;
use App\Core\Sso\SsoSignIn;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * AUTH-11, ADR 023: single sign-on before anyone is signed in.
 *
 * - POST auth/sso/start: email or host; answers the IdP URL and the
 *   browser binding, or the connections to choose from.
 * - GET auth/sso/oidc/callback, POST auth/sso/saml/{connection}/acs: the
 *   IdP's answer; always a redirect to the web app (ticket or error in the
 *   fragment).
 * - POST auth/sso/complete: ticket and binding; the normal token response,
 *   or `two_factor_required` with a challenge.
 */
class SsoAuthController
{
    public function __construct(
        private readonly SsoFlows $flows,
        private readonly TenantContext $tenants,
    ) {}

    public function start(StartSsoRequest $request): JsonResponse
    {
        $tenantId = $this->flows->tenantFor($request->validated('email'), $request->validated('host'));

        if ($tenantId === null) {
            $message = __('sso.errors.not_found');

            throw new ApiException(404, 'sso_not_found', $message, ['email' => [$message]]);
        }

        try {
            return response()->json(['data' => $this->flows->start($request->validated('connection_id'), $request->validated('origin'))]);
        } finally {
            $this->tenants->set(null);
        }
    }

    public function oidcCallback(SsoCallbackRequest $request): RedirectResponse
    {
        try {
            return $this->away($this->flows->oidcCallback(
                $request->text('state', 100),
                $request->text('code', 4096),
                $request->text('error', 200),
            ));
        } finally {
            $this->tenants->set(null);
        }
    }

    public function samlAcs(SsoCallbackRequest $request, string $connection): RedirectResponse
    {
        try {
            return $this->away($this->flows->samlCallback(
                $connection,
                $request->text('RelayState', 100),
                $request->text('SAMLResponse', 1_048_576),
            ));
        } finally {
            $this->tenants->set(null);
        }
    }

    public function complete(CompleteSsoRequest $request, SsoSignIn $signIn): JsonResponse
    {
        [$result, $mfa] = $signIn->complete(
            (string) $request->validated('ticket'),
            (string) $request->validated('binding'),
            (string) $request->ip(),
            (string) $request->userAgent(),
            $request->deviceName(),
        );

        return $result->status === SignInResult::TWO_FACTOR_REQUIRED
            ? response()->json(['status' => SignInResult::TWO_FACTOR_REQUIRED, 'challenge_id' => $result->challengeId])
            : TokenResponse::make((string) $result->token, $result->user, $request, $mfa);
    }

    /** No Referer leaves with the URL: the fragment carries the ticket. */
    private function away(string $url): RedirectResponse
    {
        return redirect()->away($url, 303)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }
}
