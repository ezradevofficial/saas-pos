<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/** RBAC-01: the permission catalogue (same as `php artisan permissions:sync`). */
class PermissionCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('permissions:sync');
    }
}
