<?php

namespace App\Core\MasterData\Defaults\Console;

use App\Core\MasterData\Defaults\ModuleDefaults;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ONB-03, MD-03, CP-01: give tenants that already have a module active the
 * defaults it declares (POS: a price list, tax categories, cash) when they
 * lack them. Lists tenant ids as the schema owner, then seeds each tenant
 * inside its own context (runtime connection, row-level security applies).
 * Idempotent: it adds only what is missing and never changes what a tenant
 * has, so it is safe on every deploy (after `payment-methods:seed-defaults`).
 */
class SeedModuleDefaultsCommand extends Command
{
    protected $signature = 'module-defaults:seed {--module= : Only this module} {--tenant= : Only this tenant id}';

    protected $description = 'Add the declared activation defaults to tenants that have a module active and lack them';

    public function handle(TenantContext $tenants, ModuleDefaults $defaults): int
    {
        $ids = DB::connection(SyncPermissions::OWNER_CONNECTION)->table('tenants')
            ->when($this->option('tenant'), fn ($query, $id) => $query->where('id', $id))
            ->orderBy('id')->pluck('id');
        $module = $this->option('module') ?: null;
        $created = 0;

        foreach ($ids as $tenantId) {
            $created += $tenants->run($tenantId, fn () => $defaults->seedTenant($module));
        }

        $this->info(sprintf('%d records created in %d tenants.', $created, $ids->count()));

        return self::SUCCESS;
    }
}
