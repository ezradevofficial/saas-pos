<?php

namespace Modules\CRM\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** CRM-23, CRM-34: the customer accepted a quote. The total is a minor digit string. */
class QuoteAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $quoteId,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $partyId,
        public readonly string $currency,
        public readonly string $totalMinor,
    ) {}
}
