<?php

namespace Modules\CRM\Dashboards;

use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;
use App\Core\Limits\UsageNotices;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\CRM\CrmServiceProvider;
use Modules\CRM\Models\Target;
use Modules\CRM\Targets\Targets;

/**
 * LAY-01, CRM-31: the targets the reader sees whose period includes today
 * (in each company's time zone), with their actual and the share reached.
 * Actuals are computed when read, so the answer is kept a short while
 * (DashboardSources::CACHE_SECONDS).
 */
class MyTargets extends DashboardSource
{
    public function key(): string
    {
        return 'crm.targets';
    }

    public function module(): string
    {
        return CrmServiceProvider::MODULE;
    }

    public function widgets(): array
    {
        return [self::LIST];
    }

    public function permissions(): array
    {
        return ['crm.target.view', 'crm.target.manage'];
    }

    public function data(User $user, array $params): array
    {
        $targets = app(Targets::class);
        $zones = Company::query()->pluck('timezone', 'id');
        $current = $targets->visible($user)->filter(function (Target $target) use ($zones) {
            $today = CarbonImmutable::now($zones[$target->company_id] ?? 'UTC')->toDateString();

            return $target->period_start->toDateString() <= $today && $target->period_end->toDateString() >= $today;
        })->values();

        return [
            'rows' => $current->take(20)->map(function (Target $target) use ($targets) {
                $actual = $targets->actual($target);

                return [
                    'id' => $target->id,
                    'title' => __("crm.targets.metrics.{$target->metric}"),
                    'subtitle' => $actual['available']
                        ? __('crm.targets.progress', [
                            'actual' => UsageNotices::money((int) $actual['actual_minor'], $target->currency, app()->getLocale()),
                            'amount' => UsageNotices::money($target->amount_minor, $target->currency, app()->getLocale()),
                            'percent' => $actual['percent'] ?? '0',
                        ])
                        : __('crm.targets.not_available'),
                    'to' => '/crm/targets',
                    'at' => $target->period_end->toDateString(),
                    'actual_minor' => $actual['actual_minor'],
                    'amount_minor' => (string) $target->amount_minor,
                    'currency' => $target->currency,
                    'percent' => $actual['percent'],
                ];
            })->values()->all(),
            'total' => $current->count(),
        ];
    }
}
