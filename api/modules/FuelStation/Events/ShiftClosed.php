<?php

namespace Modules\FuelStation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * FUEL-33: a forecourt shift was closed (after commit, once). Carries the
 * figures per currency of the shift: litres by the meters and the value the
 * attendants collected, for Accounting or a dashboard later.
 */
class ShiftClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param array<string, string> $totals litres, expected_minor, collected_minor, variance_minor */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $shiftId,
        public readonly string $locationId,
        public readonly string $currency,
        public readonly array $totals,
    ) {}
}
