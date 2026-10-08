<?php

namespace App\Core\MasterData\Items\Console;

use App\Core\MasterData\Items\DefaultUoms;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MD-02: give tenants created before units existed the default units.
 * Lists tenant ids as the schema owner, then seeds each inside its own
 * tenant context (runtime connection, row-level security applies).
 * Idempotent; safe on every deploy.
 */
class SeedDefaultUomsCommand extends Command
{
    protected $signature = 'uoms:seed-defaults';

    protected $description = 'Add the default units of measure to every tenant that lacks them';

    public function handle(TenantContext $tenants, DefaultUoms $uoms): int
    {
        $ids = DB::connection(SyncPermissions::OWNER_CONNECTION)->table('tenants')->orderBy('id')->pluck('id');
        $created = 0;

        foreach ($ids as $tenantId) {
            $created += $tenants->run($tenantId, fn () => DB::connection(TenantContext::CONNECTION)->transaction(fn () => $uoms->seed()));
        }

        $this->info(sprintf('%d units created in %d tenants.', $created, count($ids)));

        return self::SUCCESS;
    }
}
