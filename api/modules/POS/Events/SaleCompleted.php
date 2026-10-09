<?php

namespace Modules\POS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * POS-13: a completed sale was stored (once per sale: a duplicate upload
 * raises nothing). Dispatched after commit, so listeners (fiscal queue,
 * later Accounting, Inventory, Loyalty) never see a sale that rolled back.
 * Carries ids only; listeners read the sale in the tenant's context.
 */
class SaleCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $saleId,
        public readonly string $companyId,
        public readonly string $locationId,
    ) {}
}
