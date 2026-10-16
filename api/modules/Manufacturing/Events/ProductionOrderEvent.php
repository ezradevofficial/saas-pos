<?php

namespace Modules\Manufacturing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * MFG-28: what happened to a production order, for webhooks and
 * automation (ids and quantities only, base units as decimal strings),
 * after commit.
 */
abstract class ProductionOrderEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $orderId,
        public readonly string $number,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $itemId,
        public readonly string $qtyBase,
        public readonly string $qtyGoodBase,
        public readonly string $qtyScrapBase,
        // The report's id for ProductionReported.
        public readonly ?string $documentId = null,
    ) {}
}
