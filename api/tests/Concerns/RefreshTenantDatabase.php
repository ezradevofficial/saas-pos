<?php

namespace Tests\Concerns;

use Database\Seeders\PermissionCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
}
