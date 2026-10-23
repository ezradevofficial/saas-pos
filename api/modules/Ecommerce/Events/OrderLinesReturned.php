<?php

namespace Modules\Ecommerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ECOM-42: part of a delivered order came back (a return or the returned
 * side of an exchange), raised after the return commits. Inventory brings
 * back those quantities from the order line's own movements, at their cost
 * (`ecommerce.return`, the return's id); the fiscal queue gets its credit
 * note. Ids, quantities (decimal strings) and times only.
 */
class OrderLinesReturned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<array{line_id: string, order_line_id: string, item_id: string, uom_id: string, qty: string, qty_base: string}> $lines */
    public function __construct(
        public readonly string $tenantId,
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
