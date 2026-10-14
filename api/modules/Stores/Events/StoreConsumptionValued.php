<?php

namespace Modules\Stores\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * STR-09, STR-16: Inventory valued an issue or a return, once per
 * document. What Accounting needs to post department consumption: an issue
 * debits the department's expense (by department and cost centre) and
 * credits stock at the store, at `value_minor` in `currency` (the
 * company's base currency, minor units as digit strings); a return
 * reverses it. Accounting posts this, not Inventory's `StockMoved` for the
 * same `stores.*` source.
 */
class StoreConsumptionValued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const ISSUE = 'issue';

    public const RETURN = 'return';

    /**
     * @param  list<array{line_id: string, item_id: string, qty_base: string, value_minor: string}>  $lines
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $direction,
        public readonly string $documentId,
        public readonly string $number,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $locationId,
        public readonly string $departmentId,
        public readonly ?string $costCentreId,
        public readonly string $occurredAt,
        public readonly string $currency,
        public readonly string $valueMinor,
        public readonly array $lines,
    ) {}
}
