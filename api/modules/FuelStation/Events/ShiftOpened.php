<?php

namespace Modules\FuelStation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** FUEL-33: a forecourt shift was opened (after commit, once). Ids only. */
class ShiftOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $shiftId,
        public readonly string $locationId,
    ) {}
}
