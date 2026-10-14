<?php

namespace Modules\Procurement\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** PRC-11, PRC-25: a tender was awarded to a bid (and its supplier party). */
class TenderAwarded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $tenderId,
        public readonly string $companyId,
        public readonly string $bidId,
        public readonly string $partyId,
        public readonly ?string $orderId,
    ) {}
}
