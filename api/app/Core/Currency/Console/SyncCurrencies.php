<?php

namespace App\Core\Currency\Console;

use App\Core\Audit\AuditContext;
use App\Core\Currency\Currencies;
use App\Core\Currency\IcuCatalogue;
use App\Core\Currency\TenantCurrencies;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Upsert the global ISO 4217 catalogue from ICU (CUR-01), as the schema
 * owner (the runtime role may only read `currencies`, ADR 002). Idempotent;
 * never deletes (tenant currencies reference the codes). Run on every
 * deploy after the migrations, and by the seeders.
 *
 * Then every tenant's companies get their country's currencies when
 * missing (TenantCurrencies::provisionFor never overwrites a row), so
 * tenants created before CUR-01 are provisioned too. Each tenant is
 * entered on the runtime connection, under row-level security.
 */
class SyncCurrencies extends Command
{
    protected $signature = 'currencies:sync';

    protected $description = 'Upsert the ISO 4217 currency catalogue from ICU';

    public function handle(Currencies $currencies, TenantContext $tenants, TenantCurrencies $tenantCurrencies, AuditContext $audit): int
    {
        $now = now();
        $rows = array_map(fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now], IcuCatalogue::read());

        DB::connection(SyncPermissions::OWNER_CONNECTION)->transaction(function () use ($rows) {
            DB::connection(SyncPermissions::OWNER_CONNECTION)->table('currencies')->upsert(
                $rows,
                ['code'],
                ['numeric_code', 'name_en', 'name_fr', 'default_decimals', 'active_in_iso', 'updated_at'],
            );
        });

        $currencies->forget();

        $provisioned = $this->provisionTenants($tenants, $tenantCurrencies, $audit);

        $this->components->info(sprintf(
            '%d currencies, %d in ISO use; tenant currencies checked in %d tenants.',
            count($rows), count(array_filter($rows, fn (array $row) => $row['active_in_iso'])), $provisioned,
        ));

        return self::SUCCESS;
    }

    private function provisionTenants(TenantContext $tenants, TenantCurrencies $tenantCurrencies, AuditContext $audit): int
    {
        // The system acts: no user, device or request is recorded (AUD-02).
        $audit->reset();

        $ids = DB::connection(SyncPermissions::OWNER_CONNECTION)->table('tenants')->orderBy('id')->pluck('id');

        // Seeding runs with the owner as the default connection; tenant rows
        // must be written by the runtime role, under row-level security.
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection(TenantContext::CONNECTION);

        try {
            foreach ($ids as $tenantId) {
                $tenants->run($tenantId, fn () => DB::transaction(function () use ($tenantCurrencies) {
                    Company::query()->orderBy('created_at')->orderBy('id')->each(
                        fn (Company $company) => $tenantCurrencies->provisionFor($company),
                    );
                }));
            }
        } finally {
            DB::setDefaultConnection($previous);
        }

        return $ids->count();
    }
}
