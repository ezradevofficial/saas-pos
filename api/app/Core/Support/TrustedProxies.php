<?php

namespace App\Core\Support;

use Illuminate\Http\Request;

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
}
