<?php

namespace Modules\FuelStation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** FUEL-25, FUEL-33: fuel was received into a tank (after commit, once). */
class DeliveryRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $deliveryId,
        public readonly string $tankId,
        public readonly string $receivedLitres,
        public readonly string $shortageLitres,
    ) {}
}
