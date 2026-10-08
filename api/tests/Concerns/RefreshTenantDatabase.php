<?php

namespace Tests\Concerns;

use App\Core\Rbac\Console\SyncPermissions;
use Database\Seeders\PermissionCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

trait RefreshTenantDatabase
{
    use RefreshDatabase;

    /**
     * Migrations run as the schema owner; tests run as the RLS-bound app
     * role. The permission catalogue (RBAC-01) is seeded once per process,
     * right after the migrations and before the per-test transaction, so it
     * is committed and every test (and sign-up's role templates) sees it.
     */
    protected function migrateFreshUsing(): array
    {
        return ['--database' => 'pgsql_owner', '--seed' => true, '--seeder' => PermissionCatalogueSeeder::class];
    }

    /**
     * Run `permissions:sync` for permissions a test registered. The sync
     * writes as the schema owner, outside the test transaction, so the
     * permissions it adds are deleted again after the test (once the test
     * transaction has rolled back, so nothing references them any more).
     */
    protected function syncPermissionCatalogue(): void
    {
        $known = DB::connection(SyncPermissions::OWNER_CONNECTION)->table('permissions')->pluck('name')->all();

        Artisan::call('permissions:sync');

        $this->beforeApplicationDestroyed(function () use ($known) {
            DB::connection(SyncPermissions::OWNER_CONNECTION)->table('permissions')->whereNotIn('name', $known)->delete();
        });
    }
}
