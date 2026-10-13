<?php

namespace App\Core\Limits;

use App\Core\Limits\Console\MeterUsageCommand;
use App\Core\Notifications\EventTypes;
use Illuminate\Support\ServiceProvider;

/**
 * LIM-01..LIM-05: usage metering and soft limits (docs/adr/018-usage-limits.md).
 *
 * Default bindings, each replaceable by binding the interface elsewhere:
 * - PlanAllowances: SelectionAllowances (the onboarding or trial
 *   selection) until billing binds the subscription's;
 * - EmployeeCount: NoEmployees until HR binds its count;
 * - UsageOverage: MeteredUsageOverage, what billing reads (LIM-05).
 */
class LimitsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(PlanAllowances::class, SelectionAllowances::class);
        $this->app->singletonIf(EmployeeCount::class, NoEmployees::class);
        $this->app->singletonIf(UsageOverage::class, MeteredUsageOverage::class);
    }

    public function boot(): void
    {
        UsageNotices::register($this->app->make(EventTypes::class));

        if ($this->app->runningInConsole()) {
            $this->commands([MeterUsageCommand::class]);
        }
    }
}
