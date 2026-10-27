<?php

namespace Modules\Restaurant\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** REST-16: a table or tab was paid in full and closed (after commit, once). Other modules may listen. */
class OrderClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $orderId,
        public readonly string $locationId,
    ) {}
}
