<?php

namespace Modules\Accounting\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Support\EventReplays;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Integrations\Integrations;

/**
 * ACC-29: posts what POS, Inventory and Loyalty recorded before
 * Accounting was switched on, from a start date the owner chooses. Each
 * module raises its events again for the period (core EventReplays, so
 * Accounting never reads their tables) and they post exactly as live
 * ones do: idempotently by source, so running it again, or over a period
 * already posted live, posts nothing twice; what cannot post (a closed
 * period, a missing rule) becomes a posting exception.
 */
class BackfillPostings extends Command
{
    protected $signature = 'accounting:backfill
        {--from= : The first day to post (Y-m-d, UTC), chosen by the owner}
        {--to= : The last day (Y-m-d, UTC), today by default}
        {--tenant=* : Only these tenants (ids); every active tenant with Accounting by default}';

    protected $description = 'Post the POS, Inventory and Loyalty records made before Accounting was active';

    public function handle(TenantContext $tenants, DueTenants $due, ModuleRegistry $modules, EventReplays $replays, Integrations $integrations): int
    {
        $from = $this->option('from');

        if (! is_string($from) || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $from) !== 1) {
            $this->error(__('accounting.integrations.backfill_from'));

            return self::INVALID;
        }

        $start = CarbonImmutable::parse($from, 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($this->option('to') ?? 'today', 'UTC')->endOfDay();
        $only = array_values(array_filter((array) $this->option('tenant')));
        $total = 0;

        foreach ($due->active() as $tenantId) {
            if ($only !== [] && ! in_array($tenantId, $only, true)) {
                continue;
            }

            $count = $tenants->run($tenantId, fn () => $modules->isActive(AccountingServiceProvider::MODULE)
                ? $replays->replay(['pos', 'inventory', 'loyalty'], $start, $end, fn (object $event) => $integrations->handle($event))
                : 0);
            $total += $count;
            $this->line("{$tenantId}: {$count} records replayed");
        }

        $this->info("Records replayed: {$total}");

        return self::SUCCESS;
    }
}
