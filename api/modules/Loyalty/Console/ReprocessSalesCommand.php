<?php

namespace Modules\Loyalty\Console;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Loyalty\Models\LoyaltySale;
use Modules\Loyalty\Pos\PosSales;

/**
 * LOY-03: process again the POS sales Loyalty kept but could not process:
 * `failed` ones whose next attempt is due (with backoff, at most
 * LoyaltySale::RETRY_BACKOFF retries, then `failed_permanently`), and ones
 * left `pending` for over a minute. Tenants come from DueTenants (a
 * security-definer function returning ids only, ADR 002).
 */
class ReprocessSalesCommand extends Command
{
    protected $signature = 'loyalty:reprocess';

    protected $description = 'Process again the POS sales loyalty kept but could not process';

    public function handle(DueTenants $tenants, TenantContext $context, ModuleRegistry $modules, PosSales $sales): int
    {
        $now = now();
        $pendingBefore = $now->copy()->subMinute();

        foreach ($tenants->withLoyaltySalesToReprocess($now, $pendingBefore) as $tenantId) {
            $context->run($tenantId, function () use ($modules, $sales, $now, $pendingBefore) {
                if (! $modules->isActive(LoyaltyServiceProvider::MODULE)) {
                    return;
                }

                LoyaltySale::query()
                    ->where(fn ($q) => $q
                        ->where(fn ($q) => $q->where('status', LoyaltySale::FAILED)->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now)))
                        ->orWhere(fn ($q) => $q->where('status', LoyaltySale::PENDING)->where('updated_at', '<', $pendingBefore)))
                    ->orderBy('sold_at')->pluck('id')
                    ->each(fn (string $id) => $sales->process($id));
            });
        }

        return self::SUCCESS;
    }
}
