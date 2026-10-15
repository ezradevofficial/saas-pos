<?php

namespace Modules\CRM\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** CRM-21, CRM-34: a quote's flow approved it. */
class QuoteApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $quoteId,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $number,
    ) {}
}
