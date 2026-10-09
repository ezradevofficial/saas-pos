<?php

namespace Modules\POS\Sync;

/**
 * POS-05, AUTH-08: states of a void, refund or cash movement, and the
 * fingerprint of an uploaded record (ADR 004: a resend with the same id
 * but other content is refused as `payload_mismatch`).
 */
final class Records
{
    public const APPLIED = 'applied';

    /** Waiting for review: who allowed it could not be proven. */
    public const HELD = 'held';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::APPLIED, self::HELD, self::REJECTED];

    /** sha256 of the record's canonical JSON (keys sorted at every level). */
    public static function hash(array $data): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
