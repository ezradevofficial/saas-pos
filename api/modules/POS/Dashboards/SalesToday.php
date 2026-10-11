<?php

namespace Modules\POS\Dashboards;

use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;
use Modules\POS\Insights\SalesInsights;
use Modules\POS\PosServiceProvider;

/**
 * LAY-01, TEN-07: today's completed sales (each company's own today) (count and consolidated total in
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
        // A totals-only query, each company's sales in its own today (SalesDays).
        $today = app(SalesInsights::class)->today($user);

        return [
            'kind' => 'money',
            'value' => $today['total']?->minor(),
            'currency' => $today['reporting_currency'],
            'count' => $today['sales_count'],
            'complete' => $today['complete'],
            'date' => SalesDays::today($user),
            'to' => '/pos/dashboard',
        ];
    }
}
