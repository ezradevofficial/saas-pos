<?php

namespace Modules\Pharmacy\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** PHA-06, PHA-30: a lot received stock at a location. Ids and the quantity (base unit), after commit. */
class LotReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $lotId,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $itemId,
        public readonly string $qty,
    ) {}
}
