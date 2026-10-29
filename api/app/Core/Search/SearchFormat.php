<?php

namespace App\Core\Search;

use App\Core\Currency\Money;

/** How result subtitles show amounts: the currency code first, then the amount ("KES 12,450.00", "CDF 135,000"). */
final class SearchFormat
{
    public static function money(int|string $minor, string $currency): string
    {
        $decimal = Money::ofMinor($minor, $currency)->toDecimalString();
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, null);
        $sign = str_starts_with($whole, '-') ? '-' : '';
        $whole = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', ltrim($whole, '-'));

        return "{$currency} {$sign}{$whole}".($fraction === null ? '' : ".{$fraction}");
    }
}
