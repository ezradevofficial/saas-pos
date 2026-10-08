<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The global catalogues every environment needs: permissions (RBAC-01),
 * currencies (CUR-01), then the country packs (CP-01). Used by `composer migrate:fresh` and the tests
 * (RefreshTenantDatabase); run as the schema owner.
 */
class CatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PermissionCatalogueSeeder::class, CurrencyCatalogueSeeder::class, CountryPackCatalogueSeeder::class]);
    }
}
