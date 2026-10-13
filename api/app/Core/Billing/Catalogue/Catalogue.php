<?php

namespace App\Core\Billing\Catalogue;

use App\Core\Billing\Catalogue\Models\AnnualDiscount;
use App\Core\Billing\Catalogue\Models\CatalogueBundle;
use App\Core\Billing\Catalogue\Models\CatalogueCountry;
use App\Core\Billing\Catalogue\Models\CatalogueCoupon;
use App\Core\Billing\Catalogue\Models\CatalogueModule;
use App\Core\Billing\Catalogue\Models\CataloguePrice;
use App\Core\Billing\Catalogue\Pricing\BundleOffer;
use App\Core\Billing\Catalogue\Pricing\CatalogueSnapshot;
use App\Core\Billing\Catalogue\Pricing\Coupon;
use App\Core\Billing\Catalogue\Pricing\ModuleOffer;
use App\Core\Billing\Catalogue\Pricing\PriceCalculator;
use App\Core\Billing\Catalogue\Pricing\QuoteRequest;
use App\Core\Http\ApiException;
use App\Core\Platform\PlatformDatabase;

/**
 * SUB-01, ONB-03: the catalogue read from the database: the snapshot the
 * PriceCalculator prices against, the public catalogue of a country, and
 * quotes. Billing (Task 2) and the onboarding picker (Task 4) use these.
 */
class Catalogue
{
    public function __construct(private readonly PriceCalculator $calculator) {}

    /** The live modules, bundles and prices in force in $country on $date (today by default). */
    public function snapshot(string $country, ?string $date = null): CatalogueSnapshot
    {
        $date ??= now()->toDateString();
        $currency = CatalogueCountry::on(PlatformDatabase::READ)->find($country)?->currency ?? throw self::unknownCountry();

        $prices = CataloguePrice::on(PlatformDatabase::READ)->current($country, $date)->get()->keyBy(fn (CataloguePrice $p) => "{$p->item_type}:{$p->item_key}");
        $annual = AnnualDiscount::current($date);

        $modules = [];
        foreach (CatalogueModule::on(PlatformDatabase::READ)->whereNull('archived_at')->orderBy('sort')->orderBy('key')->get() as $module) {
            $price = $prices->get(CataloguePrice::MODULE.':'.$module->key);
            $modules[$module->key] = new ModuleOffer(
                key: $module->key,
                kind: $module->kind,
                unit: $module->unit,
                amount: $price?->amount,
                minimum: $price?->minimum_amount,
                includes: array_map('intval', $module->includes ?? []),
                requires: $module->requires,
                priceId: $price?->id,
            );
        }

        $bundles = [];
        foreach (CatalogueBundle::on(PlatformDatabase::READ)->whereNull('archived_at')->orderBy('sort')->orderBy('key')->get() as $bundle) {
            $price = $prices->get(CataloguePrice::BUNDLE.':'.$bundle->key);
            $bundles[$bundle->key] = new BundleOffer(
                key: $bundle->key,
                modules: array_values(array_intersect($bundle->modules ?? [], array_keys($modules))),
                addons: array_values(array_intersect($bundle->addons ?? [], array_keys($modules))),
                quoted: $bundle->quoted,
                amount: $bundle->quoted ? null : $price?->amount,
                extraOutletAmount: $price?->extra_outlet_amount,
                includedOutlets: $bundle->included_outlets,
                priceId: $price?->id,
            );
        }

        return new CatalogueSnapshot(
            $country,
            $currency,
            $modules,
            $bundles,
            $annual?->months_charged ?? 12,
            $annual?->months_in_cycle ?? 12,
        );
    }

    /**
     * The public catalogue of a country (the onboarding module picker):
     * modules and bundles with names in the request's language and prices
     * in the country's currency.
     *
     * @return array<string, mixed>
     */
    public function publicCatalogue(string $country): array
    {
        $snapshot = $this->snapshot($country);
        $names = CatalogueModule::on(PlatformDatabase::READ)->whereNull('archived_at')->pluck('name', 'key');
        $bundleNames = CatalogueBundle::on(PlatformDatabase::READ)->whereNull('archived_at')->pluck('name', 'key');
        $pick = fn (?array $name) => Models\CatalogueText::pick($name);

        return [
            'country' => $snapshot->country,
            'currency' => $snapshot->currency,
            'cycles' => QuoteRequest::CYCLES,
            'annual' => ['months_charged' => $snapshot->annualMonthsCharged, 'months_in_cycle' => $snapshot->monthsInCycle],
            'modules' => array_values(array_map(fn (ModuleOffer $m) => [
                'key' => $m->key,
                'kind' => $m->kind,
                'unit' => $m->unit,
                'name' => $pick($names[$m->key] ?? null),
                'includes' => (object) $m->includes,
                'requires' => $m->requires,
                'amount' => $m->amount,
                'minimum_amount' => $m->minimum,
            ], array_filter($snapshot->modules, fn (ModuleOffer $m) => $m->amount !== null))),
            'bundles' => array_values(array_map(fn (BundleOffer $b) => [
                'key' => $b->key,
                'name' => $pick($bundleNames[$b->key] ?? null),
                'modules' => $b->modules,
                'addons' => $b->addons,
                'included_outlets' => $b->includedOutlets,
                'quoted' => $b->quoted,
                'amount' => $b->amount,
                'extra_outlet_amount' => $b->quoted ? null : $b->extraOutletAmount,
            ], array_filter($snapshot->bundles, fn (BundleOffer $b) => $b->quoted || $b->amount !== null))),
        ];
    }

    /**
     * Price a selection at today's catalogue.
     *
     * @param  list<string>  $modules
     * @param  array<string, int>  $quantities
     * @return array<string, mixed>
     *
     * @throws Pricing\QuoteError
     * @throws ApiException 422 coupon_invalid, 422 unknown_country
     */
    public function quote(string $country, array $modules, array $quantities, string $cycle, ?string $bundle = null, ?string $couponCode = null): array
    {
        $snapshot = $this->snapshot($country);

        return $this->calculator->quote($snapshot, new QuoteRequest($modules, $quantities, $cycle, $bundle, $this->coupon($couponCode)));
    }

    /** A usable coupon by code, or null without a code. */
    public function coupon(?string $code): ?Coupon
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $coupon = CatalogueCoupon::on(PlatformDatabase::READ)->where('code', trim($code))->first();

        if ($coupon === null || ! $coupon->isUsable()) {
            throw new ApiException(422, 'coupon_invalid', __('billing.coupon.invalid'), ['coupon' => [__('billing.coupon.invalid')]]);
        }

        return $coupon->toPricing();
    }

    private static function unknownCountry(): ApiException
    {
        return new ApiException(422, 'unknown_country', __('billing.catalogue.unknown_country'), ['country' => [__('billing.catalogue.unknown_country')]]);
    }
}
