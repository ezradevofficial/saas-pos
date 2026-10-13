<?php

namespace App\Core\Billing;

/**
 * SUB-02..SUB-04: amounts the platform charges are whole steps of their
 * currency (`billing.rounding_minor`: whole shillings in KES, which M-Pesa
 * requires; cents in USD). Half up, away from zero, in integer maths.
 */
final class Rounding
{
    public static function step(string $currency): int
    {
        return max(1, (int) config("billing.rounding_minor.{$currency}", 1));
    }

    public static function round(int $minor, string $currency): int
    {
        $step = self::step($currency);
        $sign = $minor < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($minor) + $step, 2 * $step) * $step;
    }
}
