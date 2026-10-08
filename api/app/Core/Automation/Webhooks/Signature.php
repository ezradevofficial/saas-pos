<?php

namespace App\Core\Automation\Webhooks;

/**
 * AUTO-03 webhook signatures: HMAC-SHA256 with the rule's secret over
 * "<timestamp>.<body>", sent as
 *
 *   X-Webhook-Timestamp: 1791446400
 *   X-Webhook-Signature: sha256=<hex>
 *
 * The receiver recomputes it with the secret it was given and refuses old
 * timestamps (replays).
 */
final class Signature
{
    public const TIMESTAMP_HEADER = 'X-Webhook-Timestamp';

    public const SIGNATURE_HEADER = 'X-Webhook-Signature';

    public const ID_HEADER = 'X-Webhook-Id';

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function verify(string $secret, int $timestamp, string $body, string $signature): bool
    {
        return hash_equals(self::sign($secret, $timestamp, $body), $signature);
    }
}
