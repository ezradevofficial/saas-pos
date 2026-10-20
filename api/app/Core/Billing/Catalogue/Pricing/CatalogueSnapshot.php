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

    /**
     * ADM-02: this snapshot with a tenant's Enterprise deal applied. Each
     * deal line replaces the catalogue price of a module or bundle (its
     * unit amount, and the minimum or extra-outlet amount when the line
     * gives one); a quoted bundle (Enterprise) the deal prices becomes
     * priced for this tenant only. Lines naming an item the catalogue does
     * not have are ignored. The price id is `deal:<id>`, so the period
     * keeps the deal it was sold at (SUB-02).
     *
     * @param  list<array{type: string, key: string, amount: int|string, extra_outlet_amount?: int|string|null, minimum_amount?: int|string|null}>  $lines
     */
    public function withDeal(string $dealId, array $lines): self
    {
        $modules = $this->modules;
        $bundles = $this->bundles;
        $priceId = 'deal:'.$dealId;

        foreach ($lines as $line) {
            $key = (string) ($line['key'] ?? '');
            $amount = (int) $line['amount'];

            if (($line['type'] ?? null) === 'module' && isset($modules[$key])) {
                $m = $modules[$key];
                $modules[$key] = new ModuleOffer($m->key, $m->kind, $m->unit, $amount, array_key_exists('minimum_amount', $line) ? self::intOrNull($line['minimum_amount']) : $m->minimum, $m->includes, $m->requires, $priceId, deal: true);
            }

            if (($line['type'] ?? null) === 'bundle' && isset($bundles[$key])) {
                $b = $bundles[$key];
                $bundles[$key] = new BundleOffer($b->key, $b->modules, $b->addons, false, $amount, array_key_exists('extra_outlet_amount', $line) ? self::intOrNull($line['extra_outlet_amount']) : $b->extraOutletAmount, $b->includedOutlets, $priceId, deal: true);
            }
        }

        return new self($this->country, $this->currency, $modules, $bundles, $this->annualMonthsCharged, $this->monthsInCycle);
    }

    private static function intOrNull(int|string|null $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
