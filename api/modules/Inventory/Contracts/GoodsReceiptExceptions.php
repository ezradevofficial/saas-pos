<?php

namespace Modules\Inventory\Contracts;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * INV-15, STR-07 (stage 7B closing review): what a receiving module could
 * not take of a GoodsReceived, so the sender shows it on its document (a
 * flag on Procurement's GRN lines) instead of a log entry nobody reads.
 *
 * - `module`: who reports it (`inventory`, `stores`);
 * - `receiptId`: the GoodsReceived's receipt id;
 * - `lines`: `[{line_id, code}]`, `code` one of `item_unknown` (no such
 *   stock item), `unit_unknown` (a unit the item does not have),
 *   `qty_not_positive`, `serial_exists` (a serial already in stock, left
 *   out of the line).
 *
 * Raised after the receiver's posting commits, again if the GoodsReceived
 * is delivered again (the listener keeps each code once).
 */
class GoodsReceiptExceptions implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const ITEM_UNKNOWN = 'item_unknown';

    public const UNIT_UNKNOWN = 'unit_unknown';

    public const QTY_NOT_POSITIVE = 'qty_not_positive';

    public const SERIAL_EXISTS = 'serial_exists';

    /** @param list<array{line_id: string, code: string}> $lines */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $module,
        public readonly string $receiptId,
        public readonly array $lines,
    ) {}

    /** The code of a line the receiver skipped. */
    public static function skipped(mixed $item, mixed $factor): string
    {
        return match (true) {
            $item === null => self::ITEM_UNKNOWN,
            $factor === null => self::UNIT_UNKNOWN,
            default => self::QTY_NOT_POSITIVE,
        };
    }
}
