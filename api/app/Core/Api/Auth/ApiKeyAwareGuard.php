<?php

namespace App\Core\Api\Auth;

use App\Core\Api\ApiKeyAuthenticator;
use App\Core\Api\ApiKeyToken;
use App\Core\Marketplace\OAuth\OAuthAuthenticator;
use Illuminate\Http\Request;
use Laravel\Sanctum\Guard;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API-02 (ADR 020): Sanctum's guard, taught API keys. A bearer token
 * starting with `sk_` is an API key and only an API key: it is checked by
 * ApiKeyAuthenticator, never looked up as a personal access token. Every
 * other request is Sanctum's, unchanged. Every `auth:sanctum` route, in
 * core and in modules, uses it; ApiKeyAuthenticator refuses keys on routes
 * that are not public (deny by default).
 */
class ApiKeyAwareGuard extends Guard
{
    /** The longest last-used write interval accepted, in seconds (AUTH-09 bound, see SessionTimeout). */
    public const MAX_TOUCH_SECONDS = 300;

    public function __invoke(Request $request)
    {
        $bearer = $request->bearerToken();

        if (ApiKeyToken::claims($bearer)) {
            return app(ApiKeyAuthenticator::class)->authenticate($request, (string) $bearer);
        }

        // MKT-02 (ADR 024): a marketplace app's OAuth access token acts as its install's key.
        if (OAuthAuthenticator::claims($bearer)) {
            return app(OAuthAuthenticator::class)->authenticate($request);
        }

        return parent::__invoke($request);
    }

    /** The configured last-used write interval in seconds, clamped to 0–300. */
    public static function touchSeconds(): int
    {
        return max(0, min(self::MAX_TOUCH_SECONDS, (int) config('sanctum.last_used_at_seconds', 60)));
    }

    /**
     * NFR-02: write last_used_at at most once per touchSeconds() per token,
     * not on every request (Sanctum 4 has no hook for this; PlatformTokens
     * does the same for platform tokens). The idle check (AUTH-09) runs
     * before this, so it reads the last write, which lags real activity by
     * less than the interval.
     *
     * @param  PersonalAccessToken  $accessToken
     */
    protected function updateLastUsedAt($accessToken)
    {
        $last = $accessToken->last_used_at;

        if ($last !== null && $last->diffInSeconds(now(), true) < self::touchSeconds()) {
            return;
        }

        parent::updateLastUsedAt($accessToken);
    }
}
