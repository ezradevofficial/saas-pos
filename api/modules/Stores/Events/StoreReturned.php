<?php

namespace Modules\Stores\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * STR-06, STR-08, STR-16: goods came back to a store against an issue,
 * raised after the return commits. With `ledger = inventory`, Inventory
 * brings the stock back at the cost its issue went out at (each line names
 * the issue line, Inventory's `source_line_id` of the issue's movements).
 */
class StoreReturned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{line_id: string, issue_line_id: string, item_id: string, uom_id: string, qty: string, qty_base: string}>  $lines
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $returnId,
        public readonly string $number,
        public readonly string $issueId,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $locationId,
        public readonly string $departmentId,
        public readonly ?string $costCentreId,
        public readonly string $ledger,
        public readonly string $returnedAt,
        public readonly ?string $userId,
        public readonly array $lines,
    ) {}
}
