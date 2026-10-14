<?php

namespace Modules\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * INV-16: a source document moved stock (once per posting). Carries each
 * movement's signed base quantity and signed value in the company's base
 * currency (minor units as digit strings), what Accounting needs to post
 * a stock journal, and the source document's line each movement is for
 * (`source_line_id`, STR-09). Dispatched after commit.
 *
 * Accounting skips it for `stores.issue` and `stores.return` sources: it
 * posts Stores' StoreConsumptionValued for them instead (one journal per
 * movement; docs/modules/stores.md, STR-16). For those sources it may be
 * raised again for a posting delivered twice, so Stores can record values
 * it missed.
 */
class StockMoved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{id: string, kind: string, item_id: string, location_id: ?string, qty: string, value_minor: string, cost_variance_minor: string, source_line_id: ?string}>  $movements
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
