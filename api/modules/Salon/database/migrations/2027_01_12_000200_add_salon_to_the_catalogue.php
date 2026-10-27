<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Salon and services (docs/modules/salon.md): a sellable module in the
// catalogue (SUB-01), per outlet, requiring POS. Concept note 8.4 prices no
// salon module: the price below is a PLACEHOLDER (price to set) equal to
// Inventory's (KES 1,500.00 and USD 12.00 per outlet per month, minor units)
// for the owner to confirm; a change is a new price row with a later
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
            'key' => 'salon',
            'kind' => 'module',
            'unit' => 'outlet',
            'name' => json_encode(['en' => 'Salon and services', 'fr' => 'Salon et services'], JSON_UNESCAPED_UNICODE),
            'includes' => json_encode((object) []),
            'requires' => 'pos',
            // After Van sales (128), before HR (130).
            'sort' => 129,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([['KE', 'KES', 150000], ['CD', 'USD', 1200]] as [$country, $currency, $amount]) {
            DB::table('catalogue_prices')->insert([
                'id' => (string) Str::uuid7(),
                'item_type' => 'module',
                'item_key' => 'salon',
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
        DB::table('catalogue_prices')->where('item_type', 'module')->where('item_key', 'salon')->delete();
        DB::table('catalogue_modules')->where('key', 'salon')->delete();
    }
};
