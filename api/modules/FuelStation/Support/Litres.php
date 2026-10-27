<?php

namespace Modules\FuelStation\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * FUEL-28: litres are exact decimals with 3 places (the `numeric(14,3)` and
 * `numeric(16,3)` columns), never floats. Money per litre is minor units.
 */
final class Litres
{
    public const SCALE = 3;

    public static function of(BigNumber|string|int $value, RoundingMode $rounding = RoundingMode::HalfUp): BigDecimal
    {
        return BigDecimal::of((string) $value)->toScale(self::SCALE, $rounding);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero()->toScale(self::SCALE);
    }

    /** A quantity for people and JSON, always 3 places: "12.500". */
    public static function show(BigNumber|string|int|null $value): ?string
    {
        return $value === null ? null : (string) BigDecimal::of((string) $value)->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    /** price per litre (minor units) × litres, rounded half up to a whole minor unit: the line total. */
    public static function value(BigNumber|string|int $pricePerLitreMinor, BigNumber|string|int $litres): int
    {
        return BigDecimal::of((string) $pricePerLitreMinor)->multipliedBy(BigDecimal::of((string) $litres))->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    public static function max(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isGreaterThanOrEqualTo($b) ? $a : $b;
    }

    public static function abs(BigDecimal $a): BigDecimal
    {
        return $a->abs();
    }
}
