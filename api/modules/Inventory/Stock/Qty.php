<?php

namespace Modules\Inventory\Stock;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * INV-03: stock quantities are exact decimals with 6 places (the
 * `numeric(20,6)` columns), never floats; costs are whole minor units.
 * Helpers shared by the ledger, the costing and the API.
 */
final class Qty
{
    public const SCALE = 6;

    /** The largest quantity a numeric(20,6) column holds, with room for sums. */
    public const MAX = '99999999999999';

    public static function of(BigNumber|string|int $value): BigDecimal
    {
        return BigDecimal::of($value)->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero()->toScale(self::SCALE);
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

    public static function min(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isLessThan($b) ? $a : $b;
    }

    /** Whole minor units, half up. */
    public static function round(BigDecimal $value): BigInteger
    {
        return $value->toScale(0, RoundingMode::HalfUp)->toBigInteger();
    }

    /** $total × $part / $whole, rounded once to whole minor units (no drift: callers give the last part the remainder). */
    public static function share(BigInteger|int|string $total, BigDecimal $part, BigDecimal $whole): BigInteger
    {
        if ($whole->isZero()) {
            return BigInteger::zero();
        }

        return self::round(BigDecimal::of($total)->multipliedBy($part)->dividedBy($whole, 20, RoundingMode::HalfUp));
    }
}
