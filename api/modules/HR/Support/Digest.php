<?php

namespace Modules\HR\Support;

/**
 * HR-10: a keyed digest of a sensitive value (identifiers), so uniqueness
 * and lookup never need the clear value: HMAC-SHA256 over the value
 * normalised (upper case, no spaces or separators) under its own key,
 * HR_DIGEST_KEY (`hr.digest_key`), separate from APP_KEY. Outside local and
 * testing the key is required (EnvironmentGuard); in development an unset
 * key falls back to one derived from APP_KEY. Rotating it: set the new key,
 * deploy, run `hr:rekey-digests` (docs/modules/hr.md).
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
        $key = (string) config('hr.digest_key');

        if ($key !== '') {
            // EnvironmentGuard refuses a malformed key at boot; never digest under an empty or short one.
            $bytes = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

            if ($bytes === false || strlen($bytes) < 32) {
                throw new \RuntimeException('HR_DIGEST_KEY is malformed.');
            }

            return $bytes;
        }

        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('HR_DIGEST_KEY is not set.');
        }

        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return hash_hmac('sha256', 'hr.identifier-digest.v1', $appKey, true);
    }
}
