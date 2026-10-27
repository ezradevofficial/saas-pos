<?php

namespace Modules\VanSales\Console;

use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\VanSales\Models\Setting;
use Modules\VanSales\Models\Visit;

/**
 * VAN-24, NFR-08: check-in positions are personal data; they are cleared
 * once older than the company's retention (the visit stays). A company with
 * no settings row keeps the default of 30 days.
 */
class PurgePositionsCommand extends Command
{
    protected $signature = 'van-sales:purge-positions';

    protected $description = 'Clear van check-in positions older than each company retention';

    public function handle(DueTenants $tenants, TenantContext $context): int
    {
        foreach ($tenants->active() as $tenantId) {
            // Positions are personal data: they go on schedule even if the tenant has since switched the module off (NFR-08).
            $context->run($tenantId, function () use ($tenantId) {
                $cleared = 0;
                $custom = Setting::query()->pluck('gps_retention_days', 'company_id')->all();

                foreach ($custom as $companyId => $days) {
                    $cleared += $this->clear($companyId, (int) $days);
                }

                $cleared += $this->clear(null, 30, array_keys($custom));
                $this->line("{$tenantId}: {$cleared} positions cleared");
            });
        }

        return self::SUCCESS;
    }

    /** @param list<string> $except companies with their own retention */
    private function clear(?string $companyId, int $days, array $except = []): int
    {
        return Visit::query()->whereNotNull('checkin_at')->where('checkin_at', '<', now()->subDays($days))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when($companyId === null && $except !== [], fn ($q) => $q->whereNotIn('company_id', $except))
            ->update(['checkin_lat' => null, 'checkin_lng' => null, 'checkin_accuracy_m' => null, 'checkin_at' => null]);
    }
}
