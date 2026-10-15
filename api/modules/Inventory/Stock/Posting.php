<?php

namespace Modules\Inventory\Stock;

use Carbon\CarbonImmutable;

/**
 * What one source document does to stock (INV-04): its lines, posted by
 * StockLedger in one transaction. `manual` postings (adjustments,
 * transfers, counts, opening stock) respect the company's negative-stock
 * rule and a running count's freeze; others (POS sales and returns, goods
 * received) are never refused and are flagged instead (INV-13, INV-09).
 */
final class Posting
{
    /** @param list<PostingLine> $lines */
    public function __construct(
        public readonly string $companyId,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly CarbonImmutable $occurredAt,
        public readonly ?string $userId,
        public readonly array $lines,
        public readonly bool $manual = true,
        // ACC-17 (stage 7B closing review): the source document's number, kept on every movement.
        public readonly ?string $reference = null,
    ) {}
}
