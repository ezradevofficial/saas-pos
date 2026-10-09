<?php

namespace Modules\POS\Dashboards;

use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;
use Modules\POS\Insights\SalesInsights;
use Modules\POS\PosServiceProvider;

/**
 * LAY-01, TEN-07: today's completed sales (count and consolidated total in
 * the reporting currency) at the locations the reader reaches with
 * `pos.sale.view` (RBAC-04), from the POS insights. Registered by the POS
 * module, so it exists only while the module is active (RBAC-08).
 */
class SalesToday extends DashboardSource
{
    public function key(): string
    {
        return 'pos.sales_today';
    }

    public function module(): string
    {
        return PosServiceProvider::MODULE;
    }

    public function widgets(): array
    {
        return [self::KPI];
    }

    public function permissions(): array
    {
        return ['pos.sale.view'];
    }

    public function data(User $user, array $params): array
    {
        $today = SalesDays::today();
        $insights = app(SalesInsights::class)->for($user, ['from' => $today, 'to' => $today]);
        $consolidated = $insights['consolidated'];

        return [
            'kind' => 'money',
            'value' => $consolidated === null ? null : $consolidated['total']->minor(),
            'currency' => $insights['reporting_currency'],
            'count' => $insights['sales_count'],
            'complete' => $consolidated === null ? false : $consolidated['complete'],
            'date' => $today,
            'to' => '/pos/dashboard',
        ];
    }
}
