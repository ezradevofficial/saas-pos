<?php

namespace App\Core\Billing\Catalogue\Http;

use App\Core\Billing\Catalogue\Models\AnnualDiscount;
use App\Core\Billing\Catalogue\Models\CatalogueBundle;
use App\Core\Billing\Catalogue\Models\CatalogueCoupon;
use App\Core\Billing\Catalogue\Models\CatalogueModule;
use App\Core\Billing\Catalogue\Models\CataloguePrice;
use Illuminate\Support\Collection;

/** How the console shows catalogue rows (SUB-01). Amounts stay in minor units. */
final class CataloguePresenter
{
    /**
     * @param  Collection<int, CataloguePrice>|null  $prices  the item's live price rows
     * @return array<string, mixed>
     */
    public static function module(CatalogueModule $module, ?Collection $prices = null): array
    {
        return [
            'id' => $module->id,
            'key' => $module->key,
            'kind' => $module->kind,
            'unit' => $module->unit,
            'name' => $module->name,
            'includes' => (object) ($module->includes ?? []),
            'requires' => $module->requires,
            'sort' => $module->sort,
            'archived_at' => $module->archived_at?->toIso8601String(),
            ...($prices === null ? [] : ['prices' => self::pricesByCountry($prices)]),
        ];
    }

    /** @return array<string, mixed> */
    public static function bundle(CatalogueBundle $bundle, ?Collection $prices = null): array
    {
        return [
            'id' => $bundle->id,
            'key' => $bundle->key,
            'name' => $bundle->name,
            'modules' => $bundle->modules ?? [],
            'addons' => $bundle->addons ?? [],
            'included_outlets' => $bundle->included_outlets,
            'quoted' => $bundle->quoted,
            'sort' => $bundle->sort,
            'archived_at' => $bundle->archived_at?->toIso8601String(),
            ...($prices === null ? [] : ['prices' => self::pricesByCountry($prices)]),
        ];
    }

    /** @return array<string, mixed> */
    public static function price(CataloguePrice $price): array
    {
        $today = now()->toDateString();

        return [
            'id' => $price->id,
            'item_type' => $price->item_type,
            'item_key' => $price->item_key,
            'country' => $price->country,
            'currency' => $price->currency,
            'amount' => $price->amount,
            'minimum_amount' => $price->minimum_amount,
            'extra_outlet_amount' => $price->extra_outlet_amount,
            'effective_from' => $price->effective_from->toDateString(),
            'status' => $price->archived_at !== null ? 'archived' : ($price->effective_from->toDateString() > $today ? 'scheduled' : 'in_effect'),
            'archived_at' => $price->archived_at?->toIso8601String(),
            'created_at' => $price->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function coupon(CatalogueCoupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'kind' => $coupon->kind,
            'percent' => $coupon->percent,
            'amount' => $coupon->amount,
            'currency' => $coupon->currency,
            'applies_to_modules' => $coupon->applies_to_modules ?? [],
            'applies_to_bundles' => $coupon->applies_to_bundles ?? [],
            'expires_at' => $coupon->expires_at?->toIso8601String(),
            'max_redemptions' => $coupon->max_redemptions,
            'redemptions' => $coupon->redemptions,
            'status' => $coupon->status(),
            'archived_at' => $coupon->archived_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function annualDiscount(AnnualDiscount $discount): array
    {
        $today = now()->toDateString();

        return [
            'id' => $discount->id,
            'months_charged' => $discount->months_charged,
            'months_in_cycle' => $discount->months_in_cycle,
            'effective_from' => $discount->effective_from->toDateString(),
            'status' => $discount->archived_at !== null ? 'archived' : ($discount->effective_from->toDateString() > $today ? 'scheduled' : 'in_effect'),
            'archived_at' => $discount->archived_at?->toIso8601String(),
        ];
    }

    /**
     * Per country: the price in effect today and the next scheduled one.
     *
     * @param  Collection<int, CataloguePrice>  $prices
     * @return array<string, array{current: array<string, mixed>|null, next: array<string, mixed>|null}>
     */
    private static function pricesByCountry(Collection $prices): array
    {
        $today = now()->toDateString();
        $out = [];

        foreach ($prices->groupBy('country') as $country => $rows) {
            $sorted = $rows->sortBy(fn (CataloguePrice $p) => $p->effective_from->toDateString())->values();
            $current = $sorted->filter(fn (CataloguePrice $p) => $p->effective_from->toDateString() <= $today)->last();
            $next = $sorted->first(fn (CataloguePrice $p) => $p->effective_from->toDateString() > $today);
            $out[$country] = ['current' => $current ? self::price($current) : null, 'next' => $next ? self::price($next) : null];
        }

        ksort($out);

        return $out;
    }
}
