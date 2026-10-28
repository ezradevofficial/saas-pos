<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Van sales (docs/modules/van-sales.md): a sellable module in the catalogue
// catalogue (SUB-01), per device. Priced from market research, 2026-10-28
// (docs/pricing-research-2026-10.md): KES 2,500 and USD 20.00 per device per month
// (minor units below). A change is a new price row with a later
// `effective_from` (prices are versioned).
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
            // After Hotel (127), before HR (130).
            'sort' => 128,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([['KE', 'KES', 250000], ['CD', 'USD', 2000]] as [$country, $currency, $amount]) {
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
