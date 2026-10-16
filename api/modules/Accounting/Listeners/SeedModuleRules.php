<?php

namespace Modules\Accounting\Listeners;

use App\Core\Rbac\Events\ModuleActivated;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Posting\PostingDefaults;
use Throwable;

/**
 * ACC-24 (stage 7D closing review item 13): a module switched on for a
 * tenant whose companies already have a chart gets the default posting
 * rules it has none for (PostingDefaults with the module: missing event
 * types only, archived rules left alone), in each such company, while
 * Accounting is active. A company whose seeding fails is reported and
 * skipped; the activation stands.
 */
class SeedModuleRules
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly PostingDefaults $defaults,
    ) {}

    public function handle(ModuleActivated $event): void
    {
        if ($event->module === AccountingServiceProvider::MODULE) {
            return;
        }

        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(AccountingServiceProvider::MODULE)) {
                return;
            }

            $companies = Company::query()->whereIn('id', Account::query()->select('company_id')->distinct())->orderBy('id')->get();

            foreach ($companies as $company) {
                try {
                    $this->defaults->seed($company, null, $event->module);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }
}
