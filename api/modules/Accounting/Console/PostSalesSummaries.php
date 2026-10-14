<?php

namespace Modules\Accounting\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Integrations\SalesSummaries;

/** ACC-26: posts the daily summary journals of POS sales for the days that have ended, in each tenant with Accounting. */
class PostSalesSummaries extends Command
{
    protected $signature = 'accounting:post-sales-summaries';

    protected $description = 'Post the daily summary journals of POS sales';

    public function handle(TenantContext $tenants, DueTenants $due, ModuleRegistry $modules, SalesSummaries $summaries): int
    {
        $now = CarbonImmutable::now();
        $posted = 0;

        foreach ($due->active() as $tenantId) {
            $posted += $tenants->run($tenantId, fn () => $modules->isActive(AccountingServiceProvider::MODULE) ? $summaries->run($now) : 0);
        }

        $this->info("Sales summaries posted: {$posted}");

        return self::SUCCESS;
    }
}
