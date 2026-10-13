<?php

namespace App\Core\Limits;

use Carbon\CarbonImmutable;

/**
 * LIM-05: the usage beyond the plan in a billing period, for billing to
 * charge at the add-on rate on the next invoice (priced by billing through
 * the catalogue). Read from the daily meter records (usage_meters), so the
 * answer for a period never changes once its days are metered: billing
 * charges each period once by asking for it once. Implemented by
 * MeteredUsageOverage.
 */
interface UsageOverage
{
    /**
     * The overage of the tenant's UTC days in [$periodStart, $periodEnd):
     * per unit, one line per quantity over the allowance with the number of
     * days it lasted. Days without a meter record count as within the plan.
     *
     * @return list<UsageOverageLine>
     */
    public function overageFor(string $tenantId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array;
}
