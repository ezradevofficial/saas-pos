<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** CP-01, WF-09: the KE and CD public holidays (same as `php artisan country-packs:holidays KE` and `CD`). */
class PublicHolidayCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        foreach (CountryPackCatalogueSeeder::PACKS as $code) {
            if (Artisan::call('country-packs:holidays', ['code' => $code]) !== 0) {
                throw new RuntimeException("Loading the {$code} public holidays failed: ".trim(Artisan::output()));
            }
        }
    }
}
