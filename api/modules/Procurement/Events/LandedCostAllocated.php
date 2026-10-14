<?php

namespace Modules\Procurement\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * PRC-17, PRC-25: a landed cost was posted over GRNs that were already
 * posted (what was allocated to draft GRNs travels with their
 * GoodsReceived instead). The Accounting source contract
 * `procurement.landed_cost` (`amounts` in base minor digit strings:
 * `stock_value`, `expense`, `landed_cost`), and for Inventory each stock
 * line's extra base cost (docs/modules/procurement.md, Integrations):
 * `charges` [{id, description, party_id, currency, amount_minor, fx,
 * base_amount_minor}] and `allocations` [{receipt_id, receipt_line_id,
 * item_id, location_id, stock, base_amount_minor}].
 */
class LandedCostAllocated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const SOURCE = 'procurement.landed_cost';

    /**
     * @param  array<string, string>  $amounts
     * @param  list<array<string, mixed>>  $charges
     * @param  list<array<string, mixed>>  $allocations
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $landedCostId,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $date,
        public readonly string $currency,
        public readonly array $amounts,
        public readonly array $charges,
        public readonly array $allocations,
        public readonly string $reference,
    ) {}
}
