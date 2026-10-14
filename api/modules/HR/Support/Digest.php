<?php

namespace Modules\HR\Support;

/**
 * HR-10: a keyed digest of a sensitive value (identifiers), so uniqueness
 * and lookup never need the clear value: HMAC-SHA256 under a key derived
 * from the application key, over the value normalised (upper case, no
 * spaces or separators).
 */
final class Digest
{
    public static function of(string $type, string $value): string
    {
        return hash_hmac('sha256', $type.':'.self::normalise($value), self::key());
    }

    public static function normalise(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[\s\-\.\/]+/u', '', $value));
    }

    private static function key(): string
    {
        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return hash_hmac('sha256', 'hr.identifier-digest.v1', $appKey, true);
    }
}
