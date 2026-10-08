<?php

namespace App\Core\MasterData\Items;

/**
 * Review focus 4: barcodes are compared normalised: surrounding and inner
 * spaces and hyphens removed, letters upper case, leading zeros kept (a
 * barcode is a string, never a number).
 */
final class Barcode
{
    public const PATTERN = '/^[0-9A-Z]{1,48}\z/';

    /** The normalised barcode, or null when nothing valid is left. */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $barcode = strtoupper(preg_replace('/[\s\-]+/u', '', (string) $value));

        return preg_match(self::PATTERN, $barcode) === 1 ? $barcode : null;
    }
}
