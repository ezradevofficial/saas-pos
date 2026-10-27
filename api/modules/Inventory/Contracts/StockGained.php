<?php

namespace Modules\Inventory\Contracts;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * INV-28 (Fuel station, FUEL-30): the event a module dispatches once it has
 * decided, on its own approval, that stock at a location is more than the
 * books say (a tank dip above its book stock). Inventory, when active for
 * the tenant, brings each line in as an `adjustment_in` movement at an
 * estimated cost (RecordStockGained); with Inventory off nothing happens, so
 * the sender never depends on it. No approval is asked again here: the
 * sender's own flow was the decision.
 *
 * - `gainId`: the sender's document id (idempotency: posted once);
 * - `occurredAt`: ISO 8601;
 * - `reference`: the document's number, for people;
 * - `lines`: `[{line_id, item_id, qty}]`, `qty` a positive decimal string in
 *   the item's base unit.
 */
class StockGained implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<array{line_id: string, item_id: string, qty: string}> $lines */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $gainId,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $occurredAt,
        public readonly ?string $reference,
        public readonly array $lines,
        public readonly ?string $userId = null,
    ) {}
}
