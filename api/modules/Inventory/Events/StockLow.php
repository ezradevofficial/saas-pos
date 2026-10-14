<?php

namespace Modules\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * INV-11, INV-16: stock of an item at a location fell to or below its
 * reorder level (once per crossing). Quantities are decimal strings in the
 * item's base unit. Dispatched after commit.
 */
class StockLow implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $itemId,
        public readonly string $locationId,
        public readonly string $qty,
        public readonly string $reorderLevel,
        public readonly string $reorderQty,
    ) {}
}
