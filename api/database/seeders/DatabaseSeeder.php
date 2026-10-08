<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Only the global catalogues (permissions, then currencies): tenants
     * and their owners come from sign-up (POST /api/v1/auth/sign-up).
     * `composer migrate:fresh` and the tests seed with this class.
     */
    public function run(): void
    {
        $this->call([PermissionCatalogueSeeder::class, CurrencyCatalogueSeeder::class]);
    }
}
