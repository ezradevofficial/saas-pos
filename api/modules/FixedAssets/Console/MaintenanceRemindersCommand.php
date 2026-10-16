<?php

namespace Modules\FixedAssets\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\FixedAssets\FixedAssetsServiceProvider;
use Modules\FixedAssets\Maintenance\Reminders;
use Throwable;

/**
 * FA-19: maintenance reminders for every active tenant with Fixed assets
 * active, each in its own context under row-level security (ADR 002).
 * One tenant's failure is reported and never stops the others.
 */
class MaintenanceRemindersCommand extends Command
{
    protected $signature = 'fixed-assets:maintenance-reminders {--at= : The date to act as (Y-m-d), today by default}';

    protected $description = 'Remind holders of fixed_assets.maintenance.manage of maintenance falling due';

    public function handle(DueTenants $due, TenantContext $tenants, ModuleRegistry $modules, Reminders $reminders): int
    {
        $today = CarbonImmutable::parse($this->option('at') ?? 'today');
        $sent = 0;
        $failed = 0;

        foreach ($due->active() as $tenantId) {
            try {
                $tenants->run($tenantId, function () use ($modules, $reminders, $today, &$sent) {
                    if ($modules->isActive(FixedAssetsServiceProvider::MODULE)) {
                        $sent += $reminders->run($today);
                    }
                });
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->components->error("Tenant {$tenantId}: {$e->getMessage()}");
            }
        }

        $this->components->info("{$sent} reminders sent, {$failed} tenants failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
