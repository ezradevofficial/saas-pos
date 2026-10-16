<?php

namespace Modules\Accounting\Contracts;

use Modules\Accounting\Models\Period;

/**
 * ACC-08, a read contract for other modules (Fixed assets,
 * docs/modules/fixed-assets.md decision 10): the last day of a company's
 * latest closed period, or null when none is closed. Nothing on or before
 * that day may post (the database refuses it too). Callers use it only
 * while Accounting is active for the tenant.
 */
final class ClosedPeriods
{
    /** The last closed day of $companyId (Y-m-d), or null. */
    public function closedThrough(string $companyId): ?string
    {
        $end = Period::query()->where('company_id', $companyId)->where('status', Period::CLOSED)->max('ends_on');

        return $end === null ? null : substr((string) $end, 0, 10);
    }
}
