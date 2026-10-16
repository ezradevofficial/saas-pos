<?php

namespace Modules\Ecommerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ECOM-18: an order left its location (handed to the rider, or collected at
 * pickup), raised after the hand-over commits. Inventory moves its stock
 * out (`ecommerce.order`). Ids, quantities (decimal strings, `qty` in
 * `uom_id`, `qty_base` in the item's base unit) and times only.
 */
class OrderFulfilled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<array{line_id: string, item_id: string, uom_id: string, qty: string, qty_base: string}> $lines */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $orderId,
        public readonly string $number,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $locationId,
        public readonly string $at,
        public readonly ?string $userId,
        public readonly array $lines,
    ) {}
}
