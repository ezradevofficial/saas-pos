<?php

namespace Modules\Ecommerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ECOM-18, ECOM-19: an order that left its location came back (a failed
 * delivery, or a return after delivery), raised after the return commits.
 * Inventory brings back what the order took, at its cost
 * (`ecommerce.return`). `delivered` says whether the order had been
 * delivered (and so posted and fiscalised) before.
 */
class OrderReturned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $orderId,
        public readonly string $number,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $locationId,
        public readonly string $at,
        public readonly ?string $userId,
        public readonly bool $delivered,
    ) {}
}
