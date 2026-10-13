<?php

namespace Modules\POS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * POS-13: a completed sale was stored (once per sale: a duplicate upload
 * raises nothing). Dispatched after commit, so listeners (fiscal queue,
 * later Accounting, Inventory, Loyalty) never see a sale that rolled back.
 * Carries the ids and, since phase 7, `sale`: a snapshot of what was
 * stored (SaleSnapshot::sale), so other modules never read POS tables.
 */
class SaleCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $saleId,
        public readonly string $companyId,
        public readonly string $locationId,
        /** @var array<string, mixed> SaleSnapshot::sale() */
        public readonly array $sale = [],
    ) {}
}
