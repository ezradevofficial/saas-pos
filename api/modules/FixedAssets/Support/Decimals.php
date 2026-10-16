<?php

namespace Modules\FixedAssets\Support;

use Brick\Math\BigDecimal;

/** FA-09, FA-18: meter units as exact decimal strings, shown without trailing zeros. */
final class Decimals
{
    public static function show(BigDecimal|string $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** Whether $value is a whole number. */
    public static function whole(BigDecimal|string $value): bool
    {
        return ! str_contains(self::show($value), '.');
    }
}
