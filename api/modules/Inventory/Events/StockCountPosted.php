<?php

namespace Modules\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * INV-09, INV-16: a stock count was posted. Gains and losses are minor
 * units of the company's base currency as digit strings (both positive).
 */
class StockCountPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $countId,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $currency,
        public readonly string $gainMinor,
        public readonly string $lossMinor,
    ) {}
}
