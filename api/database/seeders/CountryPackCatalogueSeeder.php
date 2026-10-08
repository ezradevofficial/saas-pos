<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/** CP-01: the KE and CD country packs (same as `php artisan country-packs:publish KE` and `CD`). */
class CountryPackCatalogueSeeder extends Seeder
{
    public const PACKS = ['KE', 'CD'];

    public function run(): void
    {
        foreach (self::PACKS as $code) {
            Artisan::call('country-packs:publish', ['code' => $code]);
        }
    }
}
