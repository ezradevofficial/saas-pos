<?php

namespace Modules\HR\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\HR\Attendance\Timesheets;
use Modules\HR\HrServiceProvider;
use Modules\HR\Leave\Accruals;
use Modules\HR\Models\Employee;

/**
 * HR-13, HR-25: the daily HR run for every active tenant with HR active:
 * leave accrual as of today (idempotent), and yesterday's timesheet days
 * recomputed so planned shifts nobody clocked in to show as absent.
 * Tenant ids come from DueTenants (ADR 002); each tenant runs in its own
 * context under row-level security.
 */
class HrDailyCommand extends Command
{
    protected $signature = 'hr:daily {--at= : The date to act as (Y-m-d), today by default}';

    protected $description = 'Accrue leave and close yesterday\'s timesheet days for every tenant with HR';

    public function handle(DueTenants $due, TenantContext $tenants, ModuleRegistry $modules, Accruals $accruals, Timesheets $timesheets): int
    {
        $today = CarbonImmutable::parse($this->option('at') ?? 'today');
        $count = 0;

        $failed = 0;

        foreach ($due->active() as $tenantId) {
            // One tenant's failure is reported and never stops the others.
            try {
                $this->runTenant($tenants, $tenantId, $modules, $accruals, $timesheets, $today, $count);
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                $this->components->error("Tenant {$tenantId}: {$e->getMessage()}");
            }
        }

        $this->components->info("{$count} tenants processed, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function runTenant(TenantContext $tenants, string $tenantId, ModuleRegistry $modules, Accruals $accruals, Timesheets $timesheets, CarbonImmutable $today, int &$count): void
    {

        $tenants->run($tenantId, function () use ($modules, $accruals, $timesheets, $today, &$count) {
            if (! $modules->isActive(HrServiceProvider::MODULE)) {
                return;
            }

            $accruals->run($today);
            $yesterday = $today->subDay()->toDateString();

            foreach (Employee::query()->distinct()->pluck('company_id') as $companyId) {
                $timesheets->recomputeRange((string) $companyId, $yesterday, $yesterday);
            }

            $count++;
        });

    }
}
