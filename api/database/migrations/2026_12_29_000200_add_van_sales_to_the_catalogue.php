<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Van sales (docs/modules/van-sales.md): a sellable module in the catalogue
// (SUB-01), per device (one van, one device), requiring POS. Concept note 8.4
// prices no van sales module: the price below is a PLACEHOLDER (price to set)
// equal to Inventory's (KES 1,500.00 and USD 12.00 per device per month,
// minor units) for the owner to confirm; a change is a new price row with a
// later `effective_from` (prices are versioned).
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $from = $now->clone()->utc()->toDateString();

        DB::table('catalogue_modules')->insert([
            'id' => (string) Str::uuid7(),
            'key' => 'van_sales',
            'kind' => 'module',
            'unit' => 'device',
            'name' => json_encode(['en' => 'Van sales', 'fr' => 'Vente ambulante'], JSON_UNESCAPED_UNICODE),
            'includes' => json_encode((object) []),
            'requires' => 'pos',
            // After Restaurant (126), before HR (130).
            'sort' => 127,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([['KE', 'KES', 150000], ['CD', 'USD', 1200]] as [$country, $currency, $amount]) {
            DB::table('catalogue_prices')->insert([
                'id' => (string) Str::uuid7(),
                'item_type' => 'module',
                'item_key' => 'van_sales',
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
        DB::table('catalogue_prices')->where('item_type', 'module')->where('item_key', 'van_sales')->delete();
        DB::table('catalogue_modules')->where('key', 'van_sales')->delete();
    }
};
