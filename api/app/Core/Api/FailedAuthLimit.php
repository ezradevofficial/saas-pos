<?php

namespace App\Core\Api;

use App\Core\Http\ApiErrorRenderer;
use App\Core\Http\ApiException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * API-03, Phase 8 review M5: failed `sk_` and app-token authentications.
 *
 * Counted only once a token has failed to resolve, and per token
 * fingerprint and client address: a token that authenticates is never
 * throttled, and one client sending a bad or revoked token never blocks
 * the other keys and installs behind the same address (an app serving
 * many tenants from one host, a NAT). Past `api.rate_limits.failed_auth_per_token`
 * failures in the window, that token from that address answers 429.
 *
 * Guessing a key's secret is limited per key id (its fingerprint is the
 * key id, not the secret); the secret has 238 bits, so the limit is about
 * load, not about the secret's strength.
 */
final class FailedAuthLimit
{
    /** The limiter key of $fingerprint (a key id, or a whole bearer token) from $ip. */
    public static function key(string $kind, string $fingerprint, ?string $ip): string
    {
        return $kind.'-failures|'.hash('sha256', $fingerprint).'|'.($ip ?? '');
    }

    /** Count one failure of $key; 429 when it has already failed too often in the window. */
    public static function fail(string $key): void
    {
        $max = max(1, (int) config('api.rate_limits.failed_auth_per_token', 20));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retry = max(1, RateLimiter::availableIn($key));

            throw new ApiException(429, 'rate_limited', ApiErrorRenderer::retryMessage($retry), headers: ['Retry-After' => $retry]);
        }

        RateLimiter::hit($key, (int) config('api.rate_limits.failed_auth_decay_seconds', 600));
    }
}
