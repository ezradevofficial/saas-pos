<?php

namespace Modules\CRM\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** CRM-12, CRM-34: a lead became a customer (and, when asked, an opportunity). */
class LeadConverted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $leadId,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $partyId,
        public readonly ?string $opportunityId,
        public readonly ?string $userId,
    ) {}
}
