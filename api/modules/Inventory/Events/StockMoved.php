<?php

namespace Modules\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * INV-16: a source document moved stock (once per posting). Carries each
 * movement's signed base quantity and signed value in the company's base
 * currency (minor units as digit strings), what Accounting needs to post
 * a stock journal. Dispatched after commit.
 */
class StockMoved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{id: string, kind: string, item_id: string, location_id: ?string, qty: string, value_minor: string, cost_variance_minor: string}>  $movements
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $currency,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly array $movements,
    ) {}
}
