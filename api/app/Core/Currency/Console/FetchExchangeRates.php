<?php

namespace App\Core\Currency\Console;

use App\Core\Currency\Jobs\FetchReferenceRates;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CUR-03: queue a FetchReferenceRates job for every active company with a
 * reference feed (`rate_feed` other than none). Scheduled daily
 * (routes/console.php). Tenant ids are read as the owner; companies are
 * read in each tenant's context, under row-level security.
 */
class FetchExchangeRates extends Command
{
    protected $signature = 'exchange-rates:fetch {--date= : The day to load (Y-m-d), today by default}';

    protected $description = 'Queue the daily reference-rate fetch for companies with a feed';

    public function handle(TenantContext $tenants): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?? 'today')->toDateString();
        $tenantIds = DB::connection(SyncPermissions::OWNER_CONNECTION)->table('tenants')->orderBy('id')->pluck('id');
        $queued = 0;

        foreach ($tenantIds as $tenantId) {
            $companyIds = $tenants->run($tenantId, fn () => Company::query()
                ->whereNull('archived_at')
                ->where('rate_feed', '!=', 'none')
                ->orderBy('id')
                ->pluck('id')
                ->all());

            foreach ($companyIds as $companyId) {
                FetchReferenceRates::dispatch($tenantId, $companyId, $date);
                $queued++;
            }
        }

        $this->components->info("{$queued} reference-rate fetches queued for {$date}.");

        return self::SUCCESS;
    }
}
