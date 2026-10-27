<?php

namespace Modules\FuelStation\Shifts;

use Brick\Math\BigDecimal;
use Modules\FuelStation\Support\Litres;

/**
 * FUEL-11, FUEL-16: the meter maths, exact (brick/math) and pure, so the
 * till (pos/src/fuel/meters.js) and the server agree to the millilitre.
 *
 *   litres sold = closing − opening − pump tests + approved reset adjustments
 *
 * A pump test is fuel poured back to the tank; a reset adjustment is
 * `reading before − reading after` of an approved meter reset on the shift
 * (a replaced or rolled-over meter restarts below its old reading).
 */
final class Meters
{
    public static function litres(BigDecimal|string $opening, BigDecimal|string $closing, BigDecimal|string $tests = '0', BigDecimal|string $resets = '0'): BigDecimal
    {
        return Litres::of(
            BigDecimal::of((string) $closing)->minus(BigDecimal::of((string) $opening))->minus(BigDecimal::of((string) $tests))->plus(BigDecimal::of((string) $resets)),
        );
    }

    /**
     * FUEL-16: the lowest closing reading the shift nozzle accepts: its
     * opening reading, or after an approved reset the latest reading after it.
     */
    public static function floor(BigDecimal|string $opening, ?BigDecimal $latestResetAfter): BigDecimal
    {
        return $latestResetAfter ?? BigDecimal::of((string) $opening);
    }

    /** FUEL-11: the unrecorded litres, never negative. */
    public static function unrecorded(BigDecimal $meterLitres, BigDecimal $recordedLitres): BigDecimal
    {
        $gap = $meterLitres->minus($recordedLitres);

        return $gap->isNegative() ? Litres::zero() : Litres::of($gap);
    }
}
