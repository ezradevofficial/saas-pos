<?php

namespace Modules\Manufacturing\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Quantities are exact decimals with 6 places (the `numeric(20,6)`
 * columns) and money integers in minor units, never floats (ADR 003).
 * Manufacturing keeps its own helper so it needs nothing of Inventory
 * (MFG-01). Intermediate results keep 18 places and are rounded once.
 */
final class Qty
{
    public const SCALE = 6;

    /** The working precision of chained multiplications and divisions before the one rounding. */
    public const WORK = 18;

    public static function of(BigNumber|string|int $value): BigDecimal
    {
        return BigDecimal::of($value)->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero()->toScale(self::SCALE);
    }

    /** $a × $b ÷ $c at the working precision (not rounded to 6 places yet). */
    public static function ratio(BigNumber|string $a, BigNumber|string $b, BigNumber|string $c): BigDecimal
    {
        return BigDecimal::of($a)->multipliedBy($b)->dividedBy($c, self::WORK, RoundingMode::HalfUp);
    }

    /** A quantity for people and JSON: "12", "0.5", "-3.25" (no trailing zeros). */
    public static function show(BigNumber|string|int|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $decimal = BigDecimal::of($value)->strippedOfTrailingZeros();

        return $decimal->isZero() ? '0' : (string) $decimal;
    }

    /** round_half_up($total × $part ÷ $whole) in minor units; $whole must be positive. */
    public static function share(BigInteger|string|int $total, BigNumber|string $part, BigNumber|string $whole): BigInteger
    {
        return BigDecimal::of($total)->multipliedBy($part)->dividedBy($whole, 0, RoundingMode::HalfUp)->toBigInteger();
    }

    /** round_down($total × $percent ÷ 100) in minor units (by-product shares, MFG-16). */
    public static function percentDown(BigInteger|string|int $total, BigNumber|string $percent): BigInteger
    {
        return BigDecimal::of($total)->multipliedBy($percent)->dividedBy(100, 0, RoundingMode::Down)->toBigInteger();
    }

    /** round_half_up($minutes × $ratePerHour ÷ 60) in minor units (labour and overhead, MFG-16). */
    public static function perMinutes(int $minutes, BigInteger|string|int $ratePerHour): BigInteger
    {
        return BigDecimal::of($ratePerHour)->multipliedBy($minutes)->dividedBy(60, 0, RoundingMode::HalfUp)->toBigInteger();
    }
}
