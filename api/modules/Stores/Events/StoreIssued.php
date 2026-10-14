<?php

namespace Modules\Stores\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * STR-08, STR-16: goods left a store for a department (an issue), raised
 * after the issue commits. With `ledger = inventory`, Inventory moves the
 * stock out of the store and values it (its `StockMoved` for source
 * `stores.issue` carries the values); with `ledger = stores`, the store's
 * own ledger already moved it. Ids, quantities (decimal strings, `qty` in
 * `uom_id`, `qty_base` in the item's base unit) and times only.
 */
class StoreIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{line_id: string, item_id: string, uom_id: string, qty: string, qty_base: string}>  $lines
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $issueId,
        public readonly string $number,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $locationId,
        public readonly string $departmentId,
        public readonly ?string $costCentreId,
        public readonly string $ledger,
        public readonly string $issuedAt,
        public readonly ?string $userId,
        public readonly array $lines,
    ) {}
}
