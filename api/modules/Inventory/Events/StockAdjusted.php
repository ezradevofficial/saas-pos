<?php

namespace Modules\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * INV-10, INV-16: an adjustment was posted. Values are minor units of the
 * company's base currency as digit strings: what came in (increases) and
 * went out (decreases), for Accounting's stock adjustment journal, with
 * its branch and when it was posted (`occurredAt`, ISO 8601 UTC).
 */
class StockAdjusted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $adjustmentId,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $reason,
        public readonly string $currency,
        public readonly string $valueInMinor,
        public readonly string $valueOutMinor,
        public readonly ?string $occurredAt = null,
        public readonly ?string $branchId = null,
    ) {}
}
