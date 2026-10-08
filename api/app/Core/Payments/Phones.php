<?php

namespace App\Core\Payments;

/** Phone numbers in the form mobile money providers take. */
final class Phones
{
    /**
     * A Kenyan mobile number as 2547XXXXXXXX or 2541XXXXXXXX (what Daraja
     * takes), from 07..., 01..., +254... or 254...; null when it is not one.
     */
    public static function kenyanMobile(string $phone): ?string
    {
        $digits = preg_replace('/[\s\-()]/', '', $phone) ?? '';
        $digits = ltrim($digits, '+');

        if (preg_match('/^0([17]\d{8})$/', $digits, $m) === 1) {
            return '254'.$m[1];
        }

        if (preg_match('/^254([17]\d{8})$/', $digits, $m) === 1) {
            return '254'.$m[1];
        }

        return null;
    }
}
