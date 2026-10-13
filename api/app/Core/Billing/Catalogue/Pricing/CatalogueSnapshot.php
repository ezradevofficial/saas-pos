<?php

namespace App\Core\Billing\Catalogue\Pricing;

/**
 * The catalogue as it stands in one country on one day: the input of the
 * PriceCalculator, which never reads the database itself (ONB-03).
 */
final class CatalogueSnapshot
{
    /**
     * @param  array<string, ModuleOffer>  $modules  in catalogue order
     * @param  array<string, BundleOffer>  $bundles  in catalogue order
     */
    public function __construct(
        public readonly string $country,
        public readonly string $currency,
        public readonly array $modules,
        public readonly array $bundles,
        public readonly int $annualMonthsCharged = 12,
        public readonly int $monthsInCycle = 12,
    ) {}

    public function module(string $key): ?ModuleOffer
    {
        return $this->modules[$key] ?? null;
    }

    public function bundle(string $key): ?BundleOffer
    {
        return $this->bundles[$key] ?? null;
    }
}
