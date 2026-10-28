<?php

namespace App\Core\Payments\Pawapay;

/**
 * NFR-06, SUB-04: amounts between our minor units and pawaPay's decimal
 * strings, exactly (no floats). `$decimals` is the platform's decimals for
 * the currency (CDF 0, USD 2): "135000" for CDF, "48.50" for USD.
 */
final class PawapayAmounts
{
    public static function format(int $minor, int $decimals): string
    {
        if ($decimals === 0) {
            return (string) $minor;
        }

        $sign = $minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($minor), $decimals + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$decimals).'.'.substr($digits, -$decimals);
    }

    /** The minor units of "135000.00" or "48.5"; null when it is not a plain amount or has cents the currency does not have. */
    public static function parse(mixed $amount, int $decimals): ?int
    {
        if (! is_string($amount) || preg_match('/^(\d{1,15})(?:\.(\d{1,8}))?$/', $amount, $m) !== 1) {
            return null;
        }

        $fraction = $m[2] ?? '';

        if (strlen($fraction) > $decimals && trim(substr($fraction, $decimals), '0') !== '') {
            return null;
        }

        return (int) ($m[1].str_pad(substr($fraction, 0, $decimals), $decimals, '0'));
    }
}
