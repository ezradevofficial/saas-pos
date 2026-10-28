<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Restaurant and bar (docs/modules/restaurant.md): a sellable module in the
// catalogue (SUB-01), per outlet. Priced from market research, 2026-10-28
// (docs/pricing-research-2026-10.md): KES 2,000 and USD 15.00 per outlet per month
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
            'key' => 'restaurant',
            'kind' => 'module',
            'unit' => 'outlet',
            'name' => json_encode(['en' => 'Restaurant and bar', 'fr' => 'Restaurant et bar'], JSON_UNESCAPED_UNICODE),
            'includes' => json_encode((object) []),
            'requires' => null,
            // After Pharmacy (125), before HR (130).
            'sort' => 126,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([['KE', 'KES', 200000], ['CD', 'USD', 1500]] as [$country, $currency, $amount]) {
            DB::table('catalogue_prices')->insert([
                'id' => (string) Str::uuid7(),
                'item_type' => 'module',
                'item_key' => 'restaurant',
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
        DB::table('catalogue_prices')->where('item_type', 'module')->where('item_key', 'restaurant')->delete();
        DB::table('catalogue_modules')->where('key', 'restaurant')->delete();
    }
};
