<?php

namespace Modules\Inventory\Stock;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;

/**
 * One line of a Posting: a signed base quantity of an item at a location
 * (null: in transit) with its kind. Inbound lines may carry their total
 * cost in the company's base currency (null: estimated, INV-12); a return
 * names the movement whose cost it takes back. `fefo`: an outbound line of
 * a batch item without a batch is split over the location's batches,
 * earliest expiry first (INV-06). A receipt may say how much of its cost
 * is landed cost (`landed`, base minor units, INV-15).
 */
final class PostingLine
{
    /**
     * @param  list<string>  $serialIds
     * @param  list<array{code: string}>  $flags
     */
    public function __construct(
        public readonly string $itemId,
        public readonly ?string $locationId,
        public readonly string $kind,
        public readonly BigDecimal $qty,
        public readonly ?string $sourceLineId = null,
        public readonly ?string $batchId = null,
        public readonly ?string $uomId = null,
        public readonly ?BigDecimal $uomQty = null,
        public readonly ?BigInteger $cost = null,
        public readonly ?string $reversesMovementId = null,
        public readonly array $serialIds = [],
        public readonly array $flags = [],
        public readonly bool $fefo = false,
        public readonly bool $setsLastCost = false,
        public readonly ?BigInteger $landed = null,
    ) {}

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    public function isInbound(): bool
    {
        return $this->qty->isPositive();
    }
}
