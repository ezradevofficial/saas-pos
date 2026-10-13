<?php

namespace Modules\Accounting\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Ledger\RecurringJournals;

/**
 * ACC-06: makes the recurring journals due today, in each tenant with the
 * Accounting module active. Tenants are found by a security-definer
 * function that returns ids only; each tenant's rows are read under its
 * own row-level security.
 */
class RunRecurringJournals extends Command
{
    protected $signature = 'accounting:recurring {--on= : The day to act as (Y-m-d), today by default}';

    protected $description = 'Create the recurring journals that are due';

    public function handle(TenantContext $tenants, RecurringJournals $recurring, ModuleRegistry $modules): int
    {
        $on = CarbonImmutable::parse($this->option('on') ?? 'today');
        $ids = array_map(fn ($row) => (string) $row->id, DB::connection(TenantContext::CONNECTION)
            ->select('select t.id from public.app_tenants_with_due_recurring_journals(?::date) as t(id)', [$on->toDateString()]));
        $made = 0;

        foreach ($ids as $tenantId) {
            $made += $tenants->run($tenantId, fn () => $modules->isActive(AccountingServiceProvider::MODULE) ? $recurring->runDue($on) : 0);
        }

        $this->info("Recurring journals made: {$made}");

        return self::SUCCESS;
    }
}
