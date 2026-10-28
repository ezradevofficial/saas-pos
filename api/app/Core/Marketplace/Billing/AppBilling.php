<?php

namespace App\Core\Marketplace\Billing;

use App\Core\Billing\Catalogue\Pricing\PriceCalculator;
use App\Core\Billing\Catalogue\Pricing\QuoteRequest;
use App\Core\Billing\Contracts\AddOnCharges;
use App\Core\Billing\Invoicing\Invoicing;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\Models\MarketplaceAppVersion;
use Carbon\CarbonImmutable;

/**
 * MKT-03 (docs/adr/024-marketplace.md): paid apps are billed through the
 * subscription engine (ADR 016), as `app` lines of the tenant's invoices.
 *
 * - Installed during a paid period: the rest of the period is invoiced at
 *   once (an `upgrade` invoice, pro-rated by day like a plan upgrade).
 * - Installed during the trial: nothing until the first invoice, which
 *   carries the app like every later renewal (periodLines).
 * - Each period invoice carries one line per paid, active install in the
 *   subscription's currency and cycle: the monthly price, or the yearly
 *   one (twelve months unless the developer set it).
 * - Uninstalling stops the next renewal's line; the period already paid is
 *   not refunded. A suspended app is not billed.
 *
 * Unpaid app lines are unpaid invoice lines: dunning treats them as it
 * treats the plan (SUB-05).
 */
class AppBilling implements AddOnCharges
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly Invoicing $invoicing,
        private readonly PriceCalculator $calculator,
    ) {}

    public function periodLines(Subscription $subscription, string $cycle, CarbonImmutable $start): array
    {
        $lines = [];
        $installations = AppInstallation::query()->where('status', AppInstallation::ACTIVE)->whereNotNull('price')->with(['app', 'version'])->orderBy('installed_at')->get();

        foreach ($installations as $installation) {
            $amount = $this->amount($installation, $subscription->currency, $cycle);

            if ($amount === null || $installation->app === null || $installation->app->status === MarketplaceApp::SUSPENDED) {
                continue;
            }

            $lines[] = [
                'type' => 'app',
                'key' => $installation->app_id,
                'name' => $installation->version?->name,
                'unit' => 'app',
                'quantity' => 1,
                'billable' => 1,
                'months' => $cycle === QuoteRequest::ANNUAL ? 12 : 1,
                'unit_amount' => $amount,
                'amount' => $amount,
            ];
        }

        return $lines;
    }

    /** Charge the rest of the current paid period for a paid app just installed. */
    public function chargeInstall(AppInstallation $installation, MarketplaceAppVersion $version): void
    {
        $subscription = $this->subscriptions->current();

        $now = CarbonImmutable::now();

        // A subscribed trial (SUB-03) is charged too: its first period is invoiced already.
        if ($subscription === null || ! $subscription->hasPeriod()
            || ! (in_array($subscription->status, [Subscription::ACTIVE, Subscription::PAST_DUE], true) || $subscription->inSubscribedTrial($now))) {
            return;
        }

        $amount = $this->amount($installation, $subscription->currency, $subscription->cycle);

        if ($amount === null) {
            return;
        }

        [$periodDays, $remaining] = $this->subscriptions->days($subscription, $now);
        $charge = $this->calculator->prorate(0, $amount, $periodDays, $remaining, (int) config("billing.rounding_minor.{$subscription->currency}", 1));

        if ($charge <= 0) {
            return;
        }

        $this->invoicing->issue(
            $subscription,
            'upgrade',
            [[
                'type' => 'app',
                'key' => $installation->app_id,
                'name' => $version->name,
                'unit' => 'app',
                'quantity' => $remaining,
                'period_days' => $periodDays,
                'amount' => $charge,
            ]],
            $now->startOfDay()->max(CarbonImmutable::parse($subscription->current_period_start->toDateString(), 'UTC')),
            CarbonImmutable::parse($subscription->current_period_end->toDateString(), 'UTC'),
            $subscription->inSubscribedTrial($now) ? CarbonImmutable::instance($subscription->trial_ends_at) : $now,
        );
    }

    /** The install's price for one period of $cycle in $currency (null: free, or not in that currency). */
    private function amount(AppInstallation $installation, string $currency, string $cycle): ?int
    {
        $price = (array) $installation->price;

        if (($price['currency'] ?? null) !== $currency) {
            return null;
        }

        $amount = (int) ($cycle === QuoteRequest::ANNUAL ? ($price['yearly_minor'] ?? 0) : ($price['monthly_minor'] ?? 0));

        return $amount > 0 ? $amount : null;
    }
}
