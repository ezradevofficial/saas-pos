<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SUB-01: the module and bundle prices set from market research on 2026-10-28
// (docs/pricing-research-2026-10.md, concept note 8.4). Prices are versioned
// and never edited, so this adds a new row, effective from the day it runs,
// only for an item whose current price differs from the table below. It is
// safe to run again: when the current price already matches, it writes
// nothing. A fresh database gets the six newer modules' final prices from
// their own catalogue migrations, so only the older rows change there.
// CDF is not a billing currency in the catalogue (KE bills KES, CD bills USD).
return new class extends Migration
{
    /**
     * type:key => [KES, USD, minimum KES, minimum USD, extra outlet KES, extra outlet USD], minor units.
     *
     * @var array<string, array<int, int|null>>
     */
    private const PRICES = [
        'module:pos' => [250000, 2000, null, null, null, null],
        'module:pos_device' => [50000, 400, null, null, null, null],
        'module:inventory' => [150000, 1200, null, null, null, null],
        'module:stores' => [100000, 800, null, null, null, null],
        'module:loyalty' => [100000, 800, null, null, null, null],
        'module:ecommerce' => [250000, 2000, null, null, null, null],
        'module:accounting_essentials' => [250000, 2000, null, null, null, null],
        'module:accounting_pro' => [600000, 4500, null, null, null, null],
        'module:procurement' => [300000, 2500, null, null, null, null],
        'module:crm' => [200000, 1500, null, null, null, null],
        'module:fixed_assets' => [150000, 1200, null, null, null, null],
        'module:manufacturing' => [400000, 3000, null, null, null, null],
        'module:pharmacy' => [300000, 2500, null, null, null, null],
        'module:restaurant' => [200000, 1500, null, null, null, null],
        'module:hotel' => [450000, 3500, null, null, null, null],
        'module:fuel' => [600000, 4500, null, null, null, null],
        'module:van_sales' => [250000, 2000, null, null, null, null],
        'module:salon' => [200000, 1500, null, null, null, null],
        'module:hr' => [7000, 50, 100000, 800, null, null],
        'module:payroll' => [10000, 80, 150000, 1200, null, null],
        'module:brand_pack' => [150000, 1200, null, null, null, null],
        'bundle:starter' => [390000, 3000, null, null, null, null],
        'bundle:business' => [920000, 7400, null, null, 400000, 3200],
        'bundle:enterprise' => [4000000, 32000, null, null, null, null],
    ];

    public function up(): void
    {
        $today = now()->utc()->toDateString();
        $now = now();

        foreach (self::PRICES as $ref => [$kes, $usd, $minKes, $minUsd, $extraKes, $extraUsd]) {
            [$type, $key] = explode(':', $ref);
            $exists = $type === 'module'
                ? DB::table('catalogue_modules')->where('key', $key)->exists()
                : DB::table('catalogue_bundles')->where('key', $key)->exists();
            if (! $exists) {
                continue; // The module is not installed in this deployment.
            }

            foreach ([['KE', 'KES', $kes, $minKes, $extraKes], ['CD', 'USD', $usd, $minUsd, $extraUsd]] as [$country, $currency, $amount, $minimum, $extra]) {
                $rows = DB::table('catalogue_prices')
                    ->where('item_type', $type)->where('item_key', $key)->where('country', $country)
                    ->whereNull('archived_at');

                $current = (clone $rows)->where('effective_from', '<=', $today)->orderByDesc('effective_from')->first();
                if ($current && $current->currency === $currency && (int) $current->amount === $amount
                    && ($current->minimum_amount === null ? null : (int) $current->minimum_amount) === $minimum
                    && ($current->extra_outlet_amount === null ? null : (int) $current->extra_outlet_amount) === $extra) {
                    continue;
                }

                // One live row per item and day: if one already starts today or later, start the day after it.
                $latest = (clone $rows)->max('effective_from');
                $from = $latest !== null && $latest >= $today
                    ? now()->parse($latest)->addDay()->toDateString()
                    : $today;

                DB::table('catalogue_prices')->insert([
                    'id' => (string) Str::uuid7(),
                    'item_type' => $type,
                    'item_key' => $key,
                    'country' => $country,
                    'currency' => $currency,
                    'amount' => $amount,
                    'minimum_amount' => $minimum,
                    'extra_outlet_amount' => $extra,
                    'effective_from' => $from,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Price rows are versioned and cannot be deleted (SUB-01). To change a price, add a later row.
    }
};
