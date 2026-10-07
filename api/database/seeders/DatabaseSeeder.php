<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Only the global permission catalogue: tenants and their owners come
     * from sign-up (POST /api/v1/auth/sign-up).
     */
    public function run(): void
    {
        $this->call(PermissionCatalogueSeeder::class);
    }
}
