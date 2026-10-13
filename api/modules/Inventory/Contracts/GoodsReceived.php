<?php

namespace Modules\Inventory\Contracts;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * INV-15: the event a receiving module (Procurement's goods received note)
 * dispatches once its receipt is committed. Inventory, when active for the
 * tenant, posts each line as a `receipt` with its cost (RecordGoodsReceived);
 * with Inventory off nothing happens, so the sender never depends on it.
 *
 * - `receiptId`: the receiving document's id (idempotency: a line is
 *   posted once per receipt id and line id);
 * - `companyId`, `locationId`: where the goods arrived (the location must be
 *   the company's);
 * - `receivedAt`: ISO 8601;
 * - `reference`: the document's number, for people;
 * - `lines`: `[{line_id, item_id, uom_id?, qty, cost_minor, currency,
 *   batch_number?, expires_on?, serials?: list<string>}]`: `qty` a decimal
 *   string in `uom_id` (the item's base unit when null), `cost_minor` the
 *   line's total landed cost as a digit string in `currency` (converted to
 *   the company's base currency at the company's rate on `receivedAt`,
 *   CUR-04, when it is another currency).
 */
class GoodsReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{line_id: string, item_id: string, uom_id?: ?string, qty: string, cost_minor: string, currency: string, batch_number?: ?string, expires_on?: ?string, serials?: list<string>}>  $lines
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $receiptId,
        public readonly string $companyId,
        public readonly string $locationId,
        public readonly string $receivedAt,
        public readonly ?string $reference,
        public readonly array $lines,
        public readonly ?string $userId = null,
    ) {}
}
