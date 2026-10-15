<?php

namespace Modules\CRM\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * CRM-15, CRM-34: what OpportunityWon and OpportunityLost carry. Amounts
 * are minor digit strings; the base value is set for a won opportunity
 * (in the company's base currency).
 */
abstract class OpportunityClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $opportunityId,
        public readonly string $companyId,
        public readonly string $branchId,
        public readonly string $partyId,
        public readonly ?string $ownerId,
        public readonly string $currency,
        public readonly string $valueMinor,
        public readonly ?string $baseCurrency,
        public readonly ?string $baseValueMinor,
        public readonly string $closedOn,
    ) {}
}
