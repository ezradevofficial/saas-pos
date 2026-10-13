<?php

namespace Modules\Inventory\Stock;

use App\Core\MasterData\Items\Item;
use Brick\Math\BigDecimal;

/**
 * One typed stock line checked against the item (StockInput): its unit
 * and quantity as entered, the base quantity, the item's tracking, and the
 * batch and serials it names.
 */
final class ResolvedLine
{
    /** @param list<string> $serials serial numbers as typed (trimmed) */
    public function __construct(
        public readonly Item $item,
        public readonly string $uomId,
        public readonly BigDecimal $qty,
        public readonly BigDecimal $qtyBase,
        public readonly string $tracking,
        public readonly bool $trackExpiry,
        public readonly ?string $batchId = null,
        public readonly ?string $batchNumber = null,
        public readonly ?string $expiresOn = null,
        public readonly array $serials = [],
    ) {}
}
