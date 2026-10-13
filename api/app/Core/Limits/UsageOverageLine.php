<?php

namespace App\Core\Limits;

use InvalidArgumentException;

/**
 * LIM-05: $quantity units of $unit (Units) above the plan on $days days of
 * a period of $periodDays days. Billing prices it at the add-on rate,
 * pro-rated by days.
 */
final class UsageOverageLine
{
    public function __construct(
        public readonly string $unit,
        public readonly int $quantity,
        public readonly int $days,
        public readonly int $periodDays,
    ) {
        if (! in_array($unit, Units::ALL, true) || $quantity < 1 || $days < 1 || $periodDays < $days) {
            throw new InvalidArgumentException('An overage line has a known unit, a positive quantity and days within the period.');
        }
    }

    /** @return array{unit: string, quantity: int, days: int, period_days: int} */
    public function toArray(): array
    {
        return ['unit' => $this->unit, 'quantity' => $this->quantity, 'days' => $this->days, 'period_days' => $this->periodDays];
    }
}
