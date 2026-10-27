<?php

namespace Modules\Hotel\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Hotel\HotelServiceProvider;
use Modules\Hotel\Models\Room;
use Modules\Hotel\NightAudit\NightAudits;
use Modules\Hotel\Support\Properties;
use Throwable;

/**
 * HOT-30: the scheduled night audit. Every hour, in every active tenant with
 * the Hotel module, each property (a branch with rooms) whose audit time has
 * passed on the day after its business date is audited; a property that fell
 * behind (the server was down) catches up one day at a time, at most a week
 * per run. Idempotent: a property already audited for its date does nothing,
 * and two runs at once do not both post (the property row is locked).
 */
class NightAuditCommand extends Command
{
    protected $signature = 'hotel:night-audit {--tenant= : Only this tenant} {--branch= : Only this branch}';

    protected $description = 'Run the night audit of every hotel property that is due';

    private const MAX_CATCH_UP = 7;

    public function handle(DueTenants $tenants, TenantContext $context, ModuleRegistry $modules, NightAudits $audits): int
    {
        $ids = $this->option('tenant') ? [(string) $this->option('tenant')] : $tenants->active();

        foreach ($ids as $tenantId) {
            $context->run($tenantId, function () use ($modules, $audits, $tenantId) {
                if (! $modules->isActive(HotelServiceProvider::MODULE)) {
                    return;
                }

                $branches = Room::query()->whereNull('archived_at')->when($this->option('branch'), fn ($q, $branch) => $q->where('branch_id', $branch))->distinct()->pluck('branch_id');

                foreach ($branches as $branchId) {
                    for ($i = 0; $i < self::MAX_CATCH_UP && $audits->due($branchId); $i++) {
                        try {
                            // The date is named, so a second scheduler running at once finds this audit done instead of auditing the next day.
                            $result = $audits->run($branchId, null, app(Properties::class)->businessDate($branchId));
                            $this->line("{$tenantId}: {$branchId} {$result['run']->business_date->toDateString()} ".($result['created'] ? 'audited' : 'already done'));

                            if (! $result['created']) {
                                break;
                            }
                        } catch (Throwable $e) {
                            report($e);
                            $this->error("{$tenantId}: {$branchId} failed: {$e->getMessage()}");

                            break;
                        }
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
