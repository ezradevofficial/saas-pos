<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Fuel station (docs/modules/fuel-station.md): a sellable module in the
// catalogue (SUB-01), per outlet. Priced from market research, 2026-10-28
// (docs/pricing-research-2026-10.md): KES 6,000 and USD 45.00 per outlet per month
// (minor units below). A change is a new price row with a later
// `effective_from` (prices are versioned).
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        // The catalogue refuses rows that start in the past (catalogue_not_backdated): the price applies from the day this runs.
        $from = $now->clone()->utc()->toDateString();

        DB::table('catalogue_modules')->insert([
            'id' => (string) Str::uuid7(),
            'key' => 'fuel',
            'kind' => 'module',
            'unit' => 'outlet',
            'name' => json_encode(['en' => 'Fuel station', 'fr' => 'Station-service'], JSON_UNESCAPED_UNICODE),
            'includes' => json_encode((object) []),
            'requires' => null,
            // After Hotel (127), before HR (130).
            'sort' => 128,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([['KE', 'KES', 600000], ['CD', 'USD', 4500]] as [$country, $currency, $amount]) {
            DB::table('catalogue_prices')->insert([
                'id' => (string) Str::uuid7(),
                'item_type' => 'module',
                'item_key' => 'fuel',
                'country' => $country,
                'currency' => $currency,
                'amount' => $amount,
                'minimum_amount' => null,
                'extra_outlet_amount' => null,
                'effective_from' => $from,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('catalogue_prices')->where('item_type', 'module')->where('item_key', 'fuel')->delete();
        DB::table('catalogue_modules')->where('key', 'fuel')->delete();
    }
};
