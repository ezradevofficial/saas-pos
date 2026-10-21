<?php

namespace App\Core\Tenancy;

use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Business dates in the place's own time zone: the branch's when it has
 * one, else its company's, else UTC. Dates are stored as UTC instants, but
 * "today" for a document, a ledger entry or a default date is the company's
 * day: between 00:00 and 03:00 in Nairobi the UTC date is still yesterday.
 * Reads run under the caller's tenant context (TEN-01).
 */
final class CompanyClock
{
    public static function timezone(?string $companyId, ?string $branchId = null): string
    {
        $zone = $branchId === null ? null : Branch::query()->whereKey($branchId)->value('timezone');

        return $zone ?: (($companyId === null ? null : Company::query()->whereKey($companyId)->value('timezone')) ?: 'UTC');
    }

    /** Today (Y-m-d) in the branch's or company's time zone. */
    public static function today(?string $companyId, ?string $branchId = null): string
    {
        return CarbonImmutable::now(self::timezone($companyId, $branchId))->toDateString();
    }
}
