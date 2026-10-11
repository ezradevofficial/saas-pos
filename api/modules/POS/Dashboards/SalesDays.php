<?php

namespace Modules\POS\Dashboards;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Which calendar day "today" is for the POS dashboard widgets. Each
 * company the reader reaches with `pos.sale.view` (RBAC-04) has its own
 * today, in its own time zone: its sales are read in that day, as the
 * sales overview reads them. A chart of days ends on the latest of those
 * days, so no company's today is cut off.
 */
final class SalesDays
{
    /** @return array<string, string> company id => its today (Y-m-d) */
    public static function todayByCompany(User $user): array
    {
        $companies = app(ScopeResolver::class)->visibleIds($user, 'pos.sale.view')->companiesTouched();
        $query = Company::query();

        if ($companies !== null) {
            $query->whereKey($companies);
        }

        return $query->pluck('timezone', 'id')
            ->map(fn (?string $zone) => CarbonImmutable::now($zone ?: 'UTC')->toDateString())
            ->all();
    }

    /** The latest today among the companies the reader reaches, else today in UTC. */
    public static function today(User $user): string
    {
        $days = self::todayByCompany($user);

        return $days === [] ? CarbonImmutable::now('UTC')->toDateString() : max($days);
    }
}
