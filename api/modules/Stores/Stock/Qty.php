<?php

namespace Modules\Stores\Stock;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Store quantities are exact decimals with 6 places (the `numeric(20,6)`
 * columns), never floats. Stores keeps its own helper so it needs nothing
 * of Inventory (STR-01).
 */
final class Qty
{
    public const SCALE = 6;

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
}
