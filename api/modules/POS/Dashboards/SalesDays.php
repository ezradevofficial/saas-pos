<?php

namespace Modules\POS\Dashboards;

use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Which calendar day "today" is for the POS dashboard widgets: today in
 * the time zone of the tenant's first company by name (row-level security
 * keeps it to the tenant), else UTC. The insights then read that day in
 * each company's own time zone, as the POS dashboard does.
 */
final class SalesDays
{
    public static function today(): string
    {
        $zone = Company::query()->orderBy('name')->orderBy('id')->value('timezone') ?: 'UTC';

        return CarbonImmutable::now($zone)->toDateString();
    }
}
