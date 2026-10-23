<?php

namespace Modules\Ecommerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ECOM-43: the item given in an exchange left the order's location (once
 * nothing was left to collect), raised after commit. Inventory moves it
 * out as a sale (`ecommerce.order`, the exchange's sale id); the fiscal
 * queue gets its invoice. Ids, quantities (decimal strings) and times only.
 */
class ExchangeIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<array{line_id: string, item_id: string, uom_id: string, qty: string, qty_base: string}> $lines */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $saleId,
        public readonly string $returnId,
        public readonly string $orderId,
        public readonly string $number,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $locationId,
        public readonly string $at,
        public readonly ?string $userId,
        public readonly array $lines,
    ) {}
}
