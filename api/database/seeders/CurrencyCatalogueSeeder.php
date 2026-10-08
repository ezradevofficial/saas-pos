<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/** CUR-01: the ISO 4217 catalogue (same as `php artisan currencies:sync`). */
class CurrencyCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('currencies:sync');
    }
}
