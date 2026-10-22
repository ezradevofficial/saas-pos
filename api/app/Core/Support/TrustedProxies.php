<?php

namespace App\Core\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Which proxies may tell the app the client's address (X-Forwarded-For):
 * only the load balancer's backend range, from `TRUSTED_PROXIES`
 * (comma-separated addresses or CIDR ranges; on Linode the NodeBalancer
 * reaches the backends from 192.168.255.0/24). Empty by default: a direct
 * request's own address is used and any forwarded header is ignored, so
 * a caller cannot pose as Safaricom's callback addresses. `none` says the
 * API is reached directly on purpose (a single staging host): nothing is
 * trusted and nothing is logged.
 *
 * Read from config (`app.trusted_proxies`), never env(): with the config
 * cached (every deploy), env() is null, so the range was never applied
 * and the warning fired on every boot (deploy rehearsal 2026-10-22).
 */
final class TrustedProxies
{
    /** The forwarded headers the NodeBalancer sets. */
    public const HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT;

    /** The value that says "no load balancer, by design". */
    public const NONE = 'none';

    /** @return list<string> */
    public static function parse(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $entry) => $entry !== '' && $entry !== '*' && $entry !== '**' && strtolower($entry) !== self::NONE));
    }

    /**
     * Called once at boot (CoreServiceProvider), once config is loaded:
     * trusts the configured range for this process's requests (the global
     * TrustProxies middleware) and warns when a real environment has none.
     */
    public static function boot(Application $app): void
    {
        $value = $app['config']->get('app.trusted_proxies');
        $value = is_string($value) ? $value : null;

        if (($proxies = self::parse($value)) !== []) {
            TrustProxies::at($proxies);
            TrustProxies::withHeaders(self::HEADERS);
        }

        self::warnIfMissing((string) $app->environment(), $value);
    }

    /**
     * Logs a warning when a real environment (not local or testing) has no
     * TRUSTED_PROXIES and did not say `none`: behind the NodeBalancer every
     * client then shares its address, so per-address rate limits and
     * allowlists see one caller. True when it warned.
     */
    public static function warnIfMissing(string $environment, ?string $value): bool
    {
        if (in_array($environment, ['local', 'testing'], true) || self::parse($value) !== [] || strtolower(trim((string) $value)) === self::NONE) {
            return false;
        }

        Log::warning('TRUSTED_PROXIES is empty: behind a load balancer every client shares its address. Set it to the load balancer\'s backend range, or to none when the API is reached directly (README, pre-deploy checklist).');

        return true;
    }
}
