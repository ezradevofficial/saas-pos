<?php

namespace App\Core\Limits;

use App\Core\Limits\Models\UsageMeter;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * LIM-05: the overage of a period from the daily meter records, in the
 * tenant's own context (row-level security). For each unit, the days it
 * was above the allowance are grouped by how many units over: e.g. 1 extra
 * device for 10 days and 2 extra for 3 days give two lines. Billing prices
 * each line at the unit's add-on rate, pro-rated by days.
 */
class MeteredUsageOverage implements UsageOverage
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function overageFor(string $tenantId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $from = $periodStart->utc()->startOfDay();
        $to = $periodEnd->utc()->startOfDay();
        $periodDays = max(0, (int) $from->diffInDays($to));

        if ($periodDays === 0) {
            return [];
        }

        $rows = $this->tenants->run($tenantId, fn () => UsageMeter::query()
            ->where('day', '>=', $from->toDateString())
            ->where('day', '<', $to->toDateString())
            ->whereNotNull('allowed')
            ->whereColumn('count', '>', 'allowed')
            ->selectRaw('unit, count - allowed as quantity, count(*) as days')
            ->groupBy('unit')
            ->groupByRaw('count - allowed')
            ->toBase()
            ->get());

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = new UsageOverageLine((string) $row->unit, (int) $row->quantity, (int) $row->days, $periodDays);
        }

        usort($lines, fn (UsageOverageLine $a, UsageOverageLine $b) => [array_search($a->unit, Units::ALL, true), $a->quantity] <=> [array_search($b->unit, Units::ALL, true), $b->quantity]);

        return $lines;
    }
}
