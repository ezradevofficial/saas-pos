<?php

namespace Modules\Accounting\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Ledger\RecurringJournals;

/**
 * ACC-06: makes the recurring journals due today, in each tenant with the
 * Accounting module active. Tenants are found through DueTenants (a
 * security-definer function that returns ids only, ADR 002); each
 * tenant's rows are read under its own row-level security.
 */
class RunRecurringJournals extends Command
{
    protected $signature = 'accounting:recurring {--on= : The day to act as (Y-m-d), today by default}';

    protected $description = 'Create the recurring journals that are due';

    public function handle(TenantContext $tenants, RecurringJournals $recurring, ModuleRegistry $modules, DueTenants $due): int
    {
        $on = CarbonImmutable::parse($this->option('on') ?? 'today');
        $made = 0;

        foreach ($due->withDueRecurringJournals($on) as $tenantId) {
            $made += $tenants->run($tenantId, fn () => $modules->isActive(AccountingServiceProvider::MODULE) ? $recurring->runDue($on) : 0);
        }

        $this->info("Recurring journals made: {$made}");

        return self::SUCCESS;
    }
}
