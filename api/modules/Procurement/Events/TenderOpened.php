<?php

namespace Modules\Procurement\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** PRC-10, PRC-25: a tender's bids were opened by its committee. */
class TenderOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<string> $openerIds */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $tenderId,
        public readonly string $companyId,
        public readonly int $bids,
        public readonly array $openerIds,
    ) {}
}
