<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** CP-01: the KE and CD country packs (same as `php artisan country-packs:publish KE` and `CD`). */
class CountryPackCatalogueSeeder extends Seeder
{
    public const PACKS = ['KE', 'CD'];

    public function run(): void
    {
        foreach (static::PACKS as $code) {
            // A pack that fails to publish must stop the deploy or seed, not pass silently.
            if (Artisan::call('country-packs:publish', ['code' => $code]) !== 0) {
                throw new RuntimeException("Publishing the {$code} country pack failed: ".trim(Artisan::output()));
            }
        }
    }
}
