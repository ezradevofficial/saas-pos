<?php

namespace App\Core\Billing\Catalogue\Pricing;

/**
 * ONB-03, SUB-01: prices a selection against a catalogue snapshot. Pure:
 * no database, no clock, integer minor units only (never floats).
 *
 * - Each module is charged per unit at its country price. A module that
 *   includes units of another (POS includes 2 devices per outlet) leaves
 *   those units free; the rest are charged at that entry's price.
 * - A minimum (HR, Payroll) replaces a smaller per-unit total.
 * - With a bundle, its price covers its modules at `includedOutlets`
 *   outlets and one unit of the others; extra outlets cost the bundle's
 *   extra-outlet price, extra units of other included modules their own
 *   price, and modules outside the bundle (HR, Payroll) their own price.
 *   An add-on (Brand Pack) is sold only with a bundle that lists it. A
 *   quoted bundle (Enterprise) is never priced.
 * - Annual: the monthly total times the months in the cycle, less the
 *   months given free (12 for 10 gives 2 months).
 * - A coupon applies to the lines it names, over the months charged.
 * - Without a bundle, `suggestion` is the cheapest bundle that prices the
 *   same selection for less, with its saving.
 */
final class PriceCalculator
{
    /** Units a bundle's outlet price covers. */
    private const OUTLET_UNITS = ['outlet', 'warehouse'];

    /**
     * @return array<string, mixed>
     *
     * @throws QuoteError
     */
    public function quote(CatalogueSnapshot $catalogue, QuoteRequest $request): array
    {
        try {
            $quote = $this->price($catalogue, $request, $request->bundle);
        } catch (QuoteError $e) {
            // An add-on needs a bundle: point to the cheapest bundle that sells it.
            if ($e->reason === 'addon_requires_bundle' && $request->bundle === null) {
                $e->suggestion = $this->suggest($catalogue, $request, null);
            }

            throw $e;
        }

        $quote['suggestion'] = $request->bundle === null ? $this->suggest($catalogue, $request, $quote) : null;

        return $quote;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws QuoteError
     */
    private function price(CatalogueSnapshot $catalogue, QuoteRequest $request, ?string $bundleKey): array
    {
        if (! in_array($request->cycle, QuoteRequest::CYCLES, true)) {
            throw new QuoteError('unknown_cycle', 'cycle');
        }

        $selected = $this->selection($catalogue, $request);
        $bundle = null;

        if ($bundleKey !== null) {
            $bundle = $catalogue->bundle($bundleKey) ?? throw new QuoteError('unknown_bundle', 'bundle', $bundleKey);

            if ($bundle->quoted) {
                return $this->quoted($catalogue, $request, $bundle);
            }

            if ($bundle->amount === null) {
                throw new QuoteError('not_sold_here', 'bundle', $bundle->key);
            }
        }

        $lines = [];

        if ($bundle !== null) {
            $outlets = max(1, $request->quantity('outlet'));
            $extra = max(0, $outlets - $bundle->includedOutlets);

            if ($extra > 0 && $bundle->extraOutletAmount === null) {
                throw new QuoteError('bundle_outlet_limit', 'quantities.outlet', $bundle->key);
            }

            $lines[] = [
                'type' => 'bundle',
                'key' => $bundle->key,
                'unit' => 'outlet',
                'quantity' => $outlets,
                'included' => $bundle->includedOutlets,
                'billable' => $extra,
                'unit_amount' => $bundle->amount,
                'extra_unit_amount' => $bundle->extraOutletAmount,
                'minimum_amount' => null,
                'minimum_applied' => false,
                'amount' => self::add($bundle->amount, self::mul($extra, $bundle->extraOutletAmount ?? 0)),
            ];
        }

        $covered = $bundle?->modules ?? [];
        // Units included by the modules taken (selected or in the bundle), per entry.
        $included = [];

        foreach ($catalogue->modules as $key => $module) {
            if (! in_array($key, $selected, true) && ! in_array($key, $covered, true)) {
                continue;
            }

            foreach ($module->includes as $extraKey => $perUnit) {
                $included[$extraKey] = self::add($included[$extraKey] ?? 0, self::mul(max(0, (int) $perUnit), max(1, $request->quantity($module->unit))));
            }
        }

        foreach ($catalogue->modules as $key => $module) {
            $isSelected = in_array($key, $selected, true);

            if ($module->kind === ModuleOffer::KIND_EXTRA) {
                if (! $isSelected && ! isset($included[$key])) {
                    continue;
                }

                if ($module->requires !== null && ! in_array($module->requires, $selected, true) && ! in_array($module->requires, $covered, true)) {
                    throw new QuoteError('requires_module', 'modules', $key);
                }

                $free = $included[$key] ?? 0;
                $lines[] = $this->line($catalogue, $module, $request->quantity($module->unit, $free), $free, withMinimum: false);

                continue;
            }

            if (! $isSelected) {
                continue;
            }

            if (in_array($key, $covered, true)) {
                // Covered by the bundle; only units beyond one of a non-outlet unit cost more.
                $quantity = max(1, $request->quantity($module->unit));

                if (! in_array($module->unit, self::OUTLET_UNITS, true) && $quantity > 1) {
                    $lines[] = $this->line($catalogue, $module, $quantity, 1, withMinimum: false);
                }

                continue;
            }

            if ($module->kind === ModuleOffer::KIND_ADDON && ($bundle === null || ! in_array($key, $bundle->addons, true))) {
                throw new QuoteError($bundle === null ? 'addon_requires_bundle' : 'addon_not_available', 'modules', $key);
            }

            $lines[] = $this->line($catalogue, $module, max(1, $request->quantity($module->unit)), 0, withMinimum: true);
        }

        return $this->totals($catalogue, $request, $bundle, $lines);
    }

    /**
     * The selected keys, known and sold in this country, without repeats.
     *
     * @return list<string>
     */
    private function selection(CatalogueSnapshot $catalogue, QuoteRequest $request): array
    {
        $keys = [];

        foreach ($request->modules as $index => $key) {
            $module = $catalogue->module((string) $key) ?? throw new QuoteError('unknown_module', "modules.{$index}", (string) $key);

            if ($module->amount === null) {
                throw new QuoteError('not_sold_here', "modules.{$index}", $module->key);
            }

            $keys[$module->key] = true;
        }

        return array_keys($keys);
    }

    /** @return array<string, mixed> */
    private function line(CatalogueSnapshot $catalogue, ModuleOffer $module, int $quantity, int $included, bool $withMinimum): array
    {
        if ($module->amount === null) {
            throw new QuoteError('not_sold_here', 'modules', $module->key);
        }

        $billable = max(0, $quantity - $included);
        $amount = self::mul($billable, $module->amount);
        $minimum = $withMinimum ? $module->minimum : null;
        $minimumApplied = $minimum !== null && $amount < $minimum;

        return [
            'type' => 'module',
            'key' => $module->key,
            'unit' => $module->unit,
            'quantity' => $quantity,
            'included' => $included,
            'billable' => $billable,
            'unit_amount' => $module->amount,
            'extra_unit_amount' => null,
            'minimum_amount' => $minimum,
            'minimum_applied' => $minimumApplied,
            'amount' => $minimumApplied ? $minimum : $amount,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function totals(CatalogueSnapshot $catalogue, QuoteRequest $request, ?BundleOffer $bundle, array $lines): array
    {
        $annual = $request->cycle === QuoteRequest::ANNUAL;
        $months = $annual ? $catalogue->monthsInCycle : 1;
        $charged = $annual ? min($catalogue->annualMonthsCharged, $catalogue->monthsInCycle) : 1;
        $monthly = array_reduce(array_column($lines, 'amount'), fn (int $sum, int $amount) => self::add($sum, $amount), 0);
        $subtotal = self::mul($monthly, $months);
        $annualDiscount = self::mul($monthly, $months - $charged);
        $coupon = $this->coupon($catalogue, $request->coupon, $lines, $charged);

        return [
            'country' => $catalogue->country,
            'currency' => $catalogue->currency,
            'cycle' => $request->cycle,
            'bundle' => $bundle?->key,
            'quoted' => false,
            'lines' => $lines,
            'monthly_subtotal' => $monthly,
            'months' => $months,
            'months_charged' => $charged,
            'subtotal' => $subtotal,
            'annual_discount' => $annualDiscount,
            'coupon' => $coupon,
            'coupon_discount' => $coupon['discount'] ?? 0,
            'total' => max(0, $subtotal - $annualDiscount - ($coupon['discount'] ?? 0)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{code: string, applied: bool, reason: ?string, discount: int}|null
     */
    private function coupon(CatalogueSnapshot $catalogue, ?Coupon $coupon, array $lines, int $charged): ?array
    {
        if ($coupon === null) {
            return null;
        }

        $eligible = 0;

        foreach ($lines as $line) {
            $names = $line['type'] === 'bundle' ? $coupon->bundles : $coupon->modules;

            if ($coupon->appliesToAll() || in_array($line['key'], $names, true)) {
                $eligible = self::add($eligible, $line['amount']);
            }
        }

        $eligible = self::mul($eligible, $charged);

        [$discount, $reason] = match (true) {
            $eligible === 0 => [0, 'not_applicable'],
            $coupon->kind === Coupon::FIXED && $coupon->currency !== $catalogue->currency => [0, 'currency'],
            $coupon->kind === Coupon::FIXED => [min(max(0, (int) $coupon->amount), $eligible), null],
            // Percent of minor units, rounded half up (half a minor unit goes to the customer).
            default => [intdiv(self::add(self::mul($eligible, max(0, min(100, (int) $coupon->percent))), 50), 100), null],
        };

        return ['code' => $coupon->code, 'applied' => $discount > 0, 'reason' => $reason, 'discount' => $discount];
    }

    /** @return array<string, mixed> */
    private function quoted(CatalogueSnapshot $catalogue, QuoteRequest $request, BundleOffer $bundle): array
    {
        return [
            'country' => $catalogue->country,
            'currency' => $catalogue->currency,
            'cycle' => $request->cycle,
            'bundle' => $bundle->key,
            'quoted' => true,
            'lines' => [],
            'monthly_subtotal' => null,
            'months' => null,
            'months_charged' => null,
            'subtotal' => null,
            'annual_discount' => null,
            'coupon' => null,
            'coupon_discount' => null,
            'total' => null,
        ];
    }

    /**
     * The cheapest priced bundle that sells the same selection for less
     * (every selected module, in the bundle or beside it). Bundles that
     * share no module with the selection are not suggested.
     *
     * Without an à la carte price (an add-on that needs a bundle), the
     * cheapest bundle that can sell the selection, with no saving.
     *
     * @param  array<string, mixed>|null  $alaCarte
     * @return array{bundle: string, total: int, saving: int|null, currency: string}|null
     */
    private function suggest(CatalogueSnapshot $catalogue, QuoteRequest $request, ?array $alaCarte): ?array
    {
        $best = null;

        foreach ($catalogue->bundles as $bundle) {
            $sells = $alaCarte === null
                ? array_intersect($request->modules, $bundle->addons) !== []
                : array_intersect($request->modules, $bundle->modules) !== [];

            if ($bundle->quoted || $bundle->amount === null || ! $sells) {
                continue;
            }

            try {
                $quote = $this->price($catalogue, $request, $bundle->key);
            } catch (QuoteError) {
                continue;
            }

            if (($alaCarte === null || $quote['total'] < $alaCarte['total']) && ($best === null || $quote['total'] < $best['total'])) {
                $best = [
                    'bundle' => $bundle->key,
                    'total' => $quote['total'],
                    'saving' => $alaCarte === null ? null : $alaCarte['total'] - $quote['total'],
                    'currency' => $catalogue->currency,
                ];
            }
        }

        return $best;
    }

    /** @throws QuoteError amount_too_large instead of overflowing into a float */
    private static function mul(int $a, int $b): int
    {
        $product = $a * $b;

        if (! is_int($product)) {
            throw new QuoteError('amount_too_large', 'quantities');
        }

        return $product;
    }

    /** @throws QuoteError amount_too_large instead of overflowing into a float */
    private static function add(int $a, int $b): int
    {
        $sum = $a + $b;

        if (! is_int($sum)) {
            throw new QuoteError('amount_too_large', 'quantities');
        }

        return $sum;
    }
}
