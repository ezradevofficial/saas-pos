<?php

namespace Modules\Manufacturing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * MFG-07, MFG-12, MFG-13, MFG-15: stock a Manufacturing document moves,
 * raised after it commits, only for documents whose ledger is Inventory's
 * (an `inventory`-ledger order, or a backflush, made only while Inventory
 * is active). Inventory's listener posts it (its own module):
 *
 * | kind               | source type                         | movement kind        |
 * | issue              | manufacturing.issue                 | production_issue     |
 * | return             | manufacturing.return                | production_return    |
 * | receipt            | manufacturing.receipt               | production_receipt   |
 * | backflush          | manufacturing.backflush             | recipe_consumption   |
 * | backflush_return   | manufacturing.backflush_return      | recipe_return        |
 *
 * Ids, quantities (decimal strings, `qty` in `uom_id`, `qty_base` in the
 * item's base unit) and, for a receipt, each line's cost (`cost_minor`,
 * the company's base currency, digit string). A return names the document
 * whose movements it gives back (`originId`, an issue or the sale's
 * backflush) and, per line, the line (`origin_line_id`).
 */
class MaterialsMoved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const ISSUE = 'issue';

    public const RETURN = 'return';

    public const RECEIPT = 'receipt';

    public const BACKFLUSH = 'backflush';

    public const BACKFLUSH_RETURN = 'backflush_return';

    /** kind => the Inventory source type it posts as. */
    public const SOURCES = [
        self::ISSUE => 'manufacturing.issue',
        self::RETURN => 'manufacturing.return',
        self::RECEIPT => 'manufacturing.receipt',
        self::BACKFLUSH => 'manufacturing.backflush',
        self::BACKFLUSH_RETURN => 'manufacturing.backflush_return',
    ];

    /**
     * @param  list<array{line_id: string, item_id: string, uom_id: string, qty: string, qty_base: string, origin_line_id?: ?string, cost_minor?: ?string}>  $lines
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $kind,
        public readonly string $documentId,
        public readonly ?string $number,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $locationId,
        public readonly string $occurredAt,
        public readonly ?string $userId,
        public readonly array $lines,
        public readonly ?string $originId = null,
        // Review fix 5: a return's origin was already posted (valued): when it holds nothing for a line, that line comes back at nothing.
        public readonly bool $originPosted = false,
    ) {}

    public function sourceType(): string
    {
        return self::SOURCES[$this->kind];
    }
}
