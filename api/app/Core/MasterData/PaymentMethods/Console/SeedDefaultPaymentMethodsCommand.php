<?php

namespace App\Core\MasterData\PaymentMethods\Console;

use App\Core\MasterData\PaymentMethods\DefaultPaymentMethods;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MD-04: give companies created before payment methods existed their
 * default payment methods. Lists tenant ids as the schema owner, then
 * seeds each tenant's companies inside its own tenant context (runtime
 * connection, row-level security applies). Idempotent: an entry a company
 * has ever had, archived included, is never added again. Safe on every
 * deploy (after `uoms:seed-defaults`).
 */
class SeedDefaultPaymentMethodsCommand extends Command
{
    protected $signature = 'payment-methods:seed-defaults';

    protected $description = 'Add the default payment methods to every company that lacks them';

    public function handle(TenantContext $tenants, DefaultPaymentMethods $defaults): int
    {
        $ids = DB::connection(SyncPermissions::OWNER_CONNECTION)->table('tenants')->orderBy('id')->pluck('id');
        $created = 0;
        $companies = 0;

        foreach ($ids as $tenantId) {
            $tenants->run($tenantId, function () use ($defaults, &$created, &$companies) {
                foreach (Company::query()->whereNull('archived_at')->orderBy('id')->pluck('id') as $companyId) {
                    $created += DB::connection(TenantContext::CONNECTION)->transaction(
                        fn () => $defaults->seed(Company::query()->findOrFail($companyId)),
                    );
                    $companies++;
                }
            });
        }

        $this->info(sprintf('%d payment methods created in %d companies.', $created, $companies));

        return self::SUCCESS;
    }
}
