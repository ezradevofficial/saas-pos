<?php

namespace App\Core\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Which proxies may tell the app the client's address (X-Forwarded-For):
 * only the load balancer's backend range, from `TRUSTED_PROXIES`
 * (comma-separated addresses or CIDR ranges; on Linode the NodeBalancer
 * reaches the backends from 192.168.255.0/24). Empty by default: a direct
 * request's own address is used and any forwarded header is ignored, so
 * a caller cannot pose as Safaricom's callback addresses.
 */
final class TrustedProxies
{
    /** The forwarded headers the NodeBalancer sets. */
    public const HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT;

    /** @return list<string> */
    public static function parse(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $entry) => $entry !== '' && $entry !== '*' && $entry !== '**'));
    }

    /**
     * Logs a warning when a real environment (not local or testing) has no
     * TRUSTED_PROXIES: behind the NodeBalancer every client then shares its
     * address, so per-address rate limits and allowlists see one caller.
     * Called once at boot (CoreServiceProvider); true when it warned.
     */
    public static function warnIfMissing(string $environment, ?string $value): bool
    {
        if (in_array($environment, ['local', 'testing'], true) || self::parse($value) !== []) {
            return false;
        }

        Log::warning('TRUSTED_PROXIES is empty: behind a load balancer every client shares its address. Set it to the load balancer\'s backend range (README, pre-deploy checklist).');

        return true;
    }
}
