<?php

namespace Modules\POS\Dashboards;

use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;
use Carbon\CarbonImmutable;
use Modules\POS\Insights\SalesInsights;
use Modules\POS\PosServiceProvider;

/**
 * LAY-01, TEN-07: completed sales per day over the last `days` days (7 to
 * 31, today included), in the reporting currency, at the locations the
 * reader reaches with `pos.sale.view` (RBAC-04). For a bar or line chart.
 * Registered by the POS module, so it exists only while it is active.
 */
class SalesByDay extends DashboardSource
{
    public function key(): string
    {
        return 'pos.sales_by_day';
    }

    public function module(): string
    {
        return PosServiceProvider::MODULE;
    }

    public function widgets(): array
    {
        return [self::CHART];
    }

    public function permissions(): array
    {
        return ['pos.sale.view'];
    }

    public function rules(): array
    {
        return ['days' => ['sometimes', 'integer', 'between:7,31']];
    }

    public function data(User $user, array $params): array
    {
        $to = SalesDays::today();
        $from = CarbonImmutable::parse($to)->subDays((int) ($params['days'] ?? 7) - 1)->toDateString();
        $daily = app(SalesInsights::class)->daily($user, $from, $to);

        return [
            'kind' => 'money',
            'currency' => $daily['reporting_currency'],
            'complete' => $daily['complete'],
            'points' => array_map(fn (array $day) => [
                'label' => $day['date'],
                'value' => $day['total']?->minor(),
                'count' => $day['sales_count'],
            ], $daily['days']),
            'to' => '/pos/dashboard',
        ];
    }
}
