<?php

namespace App\Core\Billing\Overage;

use App\Core\Billing\Catalogue\Pricing\CatalogueSnapshot;
use App\Core\Billing\Catalogue\Pricing\QuoteRequest;
use App\Core\Billing\Models\Subscription;
use App\Core\Limits\Units;
use App\Core\Limits\UsageOverage;
use App\Core\Limits\UsageOverageLine;
use Carbon\CarbonImmutable;

/**
 * LIM-05: usage beyond the plan, charged at the add-on rate on the next
 * invoice. Metering (App\Core\Limits\UsageOverage) says how many units
 * were over and on how many days; this prices them against the catalogue
 * the ended period was sold at, and records the period as charged
 * (`subscriptions.overage_billed_through`) so it is charged once.
 *
 * Rates, per unit and month, from the plan:
 * - device: the extra POS device price (`pos_device`);
 * - outlet: the bundle's extra-outlet price, else the sum of the per-outlet
 *   prices of the plan's modules;
 * - employee: the sum of the per-employee prices of the plan's modules
 *   (HR, Payroll), without their minimums;
 * - user: not sold by the catalogue, never charged.
 *
 * The rate covers the months charged for the period (annual: those of the
 * annual discount) and is pro-rated by the days the units were over:
 * quantity × rate × months × days ÷ period days, half up, in minor units.
 */
class OveragePricing
{
    public function __construct(private readonly UsageOverage $usage) {}

    /** @return list<OverageLine> the priced overage of [$from, $to); none when that period was already charged */
    public function forPeriod(Subscription $subscription, CatalogueSnapshot $catalogue, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $billedThrough = $subscription->overage_billed_through?->toDateString();

        if ($billedThrough !== null && $billedThrough >= $to->toDateString()) {
            return [];
        }

        $months = $subscription->cycle === QuoteRequest::ANNUAL ? min($catalogue->annualMonthsCharged, $catalogue->monthsInCycle) : 1;
        $lines = [];

        foreach ($this->usage->overageFor($subscription->tenant_id, $from, $to) as $line) {
            [$key, $rate] = $this->rate($subscription, $catalogue, $line);

            if ($rate === null || $rate <= 0) {
                continue;
            }

            $unitAmount = $rate * $months;
            $amount = intdiv(2 * $line->quantity * $unitAmount * $line->days + $line->periodDays, 2 * $line->periodDays);

            if ($amount > 0) {
                $lines[] = new OverageLine($key, $line->unit, $line->quantity, $unitAmount, $amount, $line->days, $line->periodDays);
            }
        }

        return $lines;
    }

    /** The period ending on $to is charged (on the renewal invoice issued with it). */
    public function billed(Subscription $subscription, CarbonImmutable $to): void
    {
        $subscription->fill(['overage_billed_through' => $to->toDateString()]);
    }

    /** @return array{0: string, 1: ?int} the catalogue key the line is named after and its monthly rate */
    private function rate(Subscription $subscription, CatalogueSnapshot $catalogue, UsageOverageLine $line): array
    {
        $bundle = $subscription->bundle === null ? null : $catalogue->bundle($subscription->bundle);
        $taken = array_values(array_unique([...($subscription->modules ?? []), ...($bundle?->modules ?? [])]));
        $sum = function (string $unit) use ($catalogue, $taken): ?int {
            $total = null;

            foreach ($taken as $key) {
                $module = $catalogue->module($key);

                if ($module !== null && $module->unit === $unit && $module->amount !== null && $module->kind !== 'extra') {
                    $total = ($total ?? 0) + $module->amount;
                }
            }

            return $total;
        };

        return match ($line->unit) {
            Units::DEVICE => ['pos_device', $catalogue->module('pos_device')?->amount],
            Units::OUTLET => ['outlet', $bundle?->extraOutletAmount ?? $sum('outlet')],
            Units::EMPLOYEE => ['employee', $sum('employee')],
            default => [$line->unit, null],
        };
    }
}
