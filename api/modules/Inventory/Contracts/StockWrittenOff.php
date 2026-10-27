<?php

namespace Modules\Inventory\Contracts;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * INV-26 (Pharmacy, PHA-12): the event a module dispatches once it has
 * decided, on its own approval, that stock at a location is gone (expired,
 * damaged or recalled medicines). Inventory, when active for the tenant,
 * takes each line out as an `adjustment_out` movement at the company's cost
 * (RecordStockWrittenOff); with Inventory off nothing happens, so the
 * sender never depends on it. No approval is asked again here: the sender's
 * own flow was the decision.
 *
 * - `writeOffId`: the sender's document id (idempotency: posted once);
 * - `occurredAt`: ISO 8601;
 * - `reference`: the document's number, for people;
 * - `lines`: `[{line_id, item_id, qty, batch_number?}]`, `qty` a positive
 *   decimal string in the item's base unit;
 * - `source`: the ledger source of the movements (`pharmacy.write_off` when
 *   null; Fuel station sends `fuel.tank_loss`, FUEL-30).
 */
class StockWrittenOff implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<array{line_id: string, item_id: string, qty: string, batch_number?: ?string}> $lines */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $writeOffId,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $occurredAt,
        public readonly ?string $reference,
        public readonly array $lines,
        public readonly ?string $userId = null,
        public readonly ?string $source = null,
    ) {}
}
