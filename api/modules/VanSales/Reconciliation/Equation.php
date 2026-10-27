<?php

namespace Modules\VanSales\Reconciliation;

use Brick\Math\BigDecimal;
use Modules\VanSales\Support\Qty;

/**
 * VAN-19, VAN-20: the day's reconciliation maths, pure.
 *
 * Stock, per item, in the item's base unit:
 *   load = sold + returned + damaged + variance
 * where `load` is what the van had to account for (the confirmed load plus
 * customer returns taken back) and `variance` is what is left: a shortage is
 * positive, a surplus negative.
 *
 * Money, per currency and kind (cash, mobile money), in minor units:
 *   variance = counted - expected
 * so a shortage of cash is negative. Credit never counts.
 */
final class Equation
{
    public static function variance(BigDecimal|string $load, BigDecimal|string $sold, BigDecimal|string $returned, BigDecimal|string $damaged): BigDecimal
    {
        return Qty::of(BigDecimal::of($load)->minus($sold)->minus($returned)->minus($damaged));
    }

    public static function tenderVariance(int $expectedMinor, int $countedMinor): int
    {
        return $countedMinor - $expectedMinor;
    }
}
