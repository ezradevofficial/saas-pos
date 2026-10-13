<?php

namespace App\Core\Billing\Overage;

use InvalidArgumentException;

/**
 * LIM-05: one item used beyond the plan in a period: the catalogue entry
 * whose add-on rate applies (`key`, e.g. `pos_device`, `pos`, `hr`), its
 * unit, how many units over the plan (`quantity`), the rate per unit and
 * the amount, in minor units. `days` and `periodDays` say how much of the
 * period the extra units were used, when the rate was pro-rated.
 */
final class OverageLine
{
    public function __construct(
        public readonly string $key,
        public readonly string $unit,
        public readonly int $quantity,
        public readonly int $unitAmount,
        public readonly int $amount,
        public readonly ?int $days = null,
        public readonly ?int $periodDays = null,
    ) {
        if ($quantity < 1 || $unitAmount < 0 || $amount < 0) {
            throw new InvalidArgumentException('An overage line has a positive quantity and amounts that are not negative.');
        }
    }
}
