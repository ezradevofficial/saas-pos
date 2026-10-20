<?php

namespace App\Core\Webhooks\Delivery;

/**
 * HOOK-01 signatures, as the Standard Webhooks specification defines them
 * (docs/adr/021-webhooks.md), so receivers can verify with its libraries:
 *
 *   Webhook-Id: <event id>
 *   Webhook-Timestamp: <unix seconds>
 *   Webhook-Signature: v1,<base64 HMAC-SHA256> [v1,<...> during a rotation]
 *
 * The signed content is "<id>.<timestamp>.<body>"; the key is the bytes of
 * the secret after `whsec_` (base64). Receivers refuse timestamps more than
 * five minutes away from their clock (replays) and compare in constant time.
 */
final class WebhookSignature
{
    public const ID_HEADER = 'Webhook-Id';

    public const TIMESTAMP_HEADER = 'Webhook-Timestamp';

    public const SIGNATURE_HEADER = 'Webhook-Signature';

    public const PREFIX = 'whsec_';

    public const TOLERANCE = 300;

    /** A new signing secret: `whsec_` and 32 random bytes in base64. */
    public static function newSecret(): string
    {
        return self::PREFIX.base64_encode(random_bytes(32));
    }

    public static function sign(string $secret, string $id, int $timestamp, string $body): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, self::key($secret), true));
    }

    /**
     * The Webhook-Signature header: one signature per secret, space separated.
     *
     * @param  list<string>  $secrets
     */
    public static function header(array $secrets, string $id, int $timestamp, string $body): string
    {
        return implode(' ', array_map(fn (string $secret) => self::sign($secret, $id, $timestamp, $body), $secrets));
    }

    /** Whether $header carries a valid signature by $secret, within the tolerance of $now. */
    public static function verify(string $secret, string $id, int $timestamp, string $body, string $header, ?int $now = null): bool
    {
        if (abs(($now ?? time()) - $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = self::sign($secret, $id, $timestamp, $body);

        foreach (preg_split('/\s+/', trim($header)) ?: [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private static function key(string $secret): string
    {
        $encoded = str_starts_with($secret, self::PREFIX) ? substr($secret, strlen(self::PREFIX)) : $secret;
        $key = base64_decode($encoded, true);

        return $key === false ? $secret : $key;
    }
}
