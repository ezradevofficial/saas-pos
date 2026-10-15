<?php

namespace Modules\Procurement\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** PRC-25: a PO's flow approved it (for webhooks and other modules). Amounts are minor digit strings. */
class PurchaseOrderApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $orderId,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $partyId,
        public readonly string $number,
        public readonly string $currency,
        public readonly string $totalMinor,
        public readonly string $baseCurrency,
        public readonly string $baseTotalMinor,
    ) {}
}
