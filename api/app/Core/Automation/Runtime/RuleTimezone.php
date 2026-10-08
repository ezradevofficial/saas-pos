<?php

namespace App\Core\Automation\Runtime;

use App\Core\Tenancy\Models\Company;

/**
 * The time zone a rule's dates and schedules are read in (AUTO-01): its
 * company's; for a rule of every company, the one time zone the tenant's
 * companies share (UTC without companies). When they are in different
 * zones a schedule needs a company (null is answered).
 */
class RuleTimezone
{
    public function forCompany(?string $companyId): ?string
    {
        if ($companyId !== null) {
            $timezone = Company::query()->whereKey($companyId)->value('timezone');

            return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
        }

        $zones = Company::query()->whereNull('archived_at')->distinct()->pluck('timezone')
            ->map(fn ($zone) => is_string($zone) && $zone !== '' ? $zone : 'UTC')->unique()->values();

        return match ($zones->count()) {
            0 => 'UTC',
            1 => $zones->first(),
            default => null,
        };
    }
}
