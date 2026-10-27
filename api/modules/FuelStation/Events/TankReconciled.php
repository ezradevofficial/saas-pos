<?php

namespace Modules\FuelStation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** FUEL-26, FUEL-33: a tank's dip was reconciled and its loss or gain settled (accepted or approved), after commit. */
class TankReconciled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $reconciliationId,
        public readonly string $tankId,
        public readonly string $status,
        public readonly string $varianceLitres,
    ) {}
}
