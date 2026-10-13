<?php

namespace App\Core\Limits;

use App\Core\Billing\Catalogue\Catalogue;
use App\Core\Billing\Catalogue\Pricing\QuoteError;
use App\Core\Http\ApiException;

/**
 * LIM-02: the extra monthly cost of the units above the plan, from the
 * catalogue quote (ADR 013): the plan's selection priced with each
 * exceeded unit at its count, less the plan priced at its allowances.
 * Integer minor units in the catalogue's currency for the plan's country.
 * Units the catalogue does not sell (users) have no price.
 */
class OverageCost
{
    public function __construct(private readonly Catalogue $catalogue) {}

    /**
     * @param  array<string, int>  $counts  unit => count
     * @return array{currency: string, total: int, units: array<string, int>}|null null without a plan or a price
     */
    public function monthly(Allowances $allowances, array $counts): ?array
    {
        $plan = $allowances->plan;

        if ($plan === null) {
            return null;
        }

        $base = $plan->quantities;
        $over = [];

        foreach (Units::QUOTE_UNITS as $unit => $quoteUnit) {
            $allowed = $allowances->of($unit);

            if ($allowed === null) {
                continue;
            }

            $base[$quoteUnit] = $allowed;

            if (($counts[$unit] ?? 0) > $allowed) {
                $over[$unit] = $counts[$unit];
            }
        }

        try {
            $baseQuote = $this->quote($plan, $base);
            $units = [];

            foreach ($over as $unit => $count) {
                $units[$unit] = max(0, $this->quote($plan, [...$base, Units::QUOTE_UNITS[$unit] => $count])['monthly_subtotal'] - $baseQuote['monthly_subtotal']);
            }

            $all = $base;
            foreach ($over as $unit => $count) {
                $all[Units::QUOTE_UNITS[$unit]] = $count;
            }

            $total = max(0, $this->quote($plan, $all)['monthly_subtotal'] - $baseQuote['monthly_subtotal']);
        } catch (QuoteError|ApiException) {
            return null;
        }

        return ['currency' => $baseQuote['currency'], 'total' => $total, 'units' => $units];
    }

    /**
     * @param  array<string, int>  $quantities
     * @return array<string, mixed>
     */
    private function quote(PlanSelection $plan, array $quantities): array
    {
        $quote = $this->catalogue->quote($plan->country, $plan->modules, $quantities, 'monthly', $plan->bundle);

        if (($quote['quoted'] ?? false) === true || ! isset($quote['monthly_subtotal'], $quote['currency'])) {
            throw new QuoteError('quoted', 'bundle');
        }

        return $quote;
    }
}
