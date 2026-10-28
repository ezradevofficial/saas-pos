<?php

namespace App\Core\Billing\Http;

use App\Core\Billing\Models\BillingPayment;
use App\Core\Billing\Models\BillingPaymentMethod;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\PlatformSettings;
use Carbon\CarbonImmutable;

/** SUB-02..SUB-06: the subscription, its payments and methods as the API shows them (money as `{amount_minor, currency}`). */
final class SubscriptionPresenter
{
    /** SUB-05: what every signed-in user sees in the banner. */
    public static function status(?Subscription $subscription): array
    {
        if ($subscription === null) {
            return ['status' => null];
        }

        $now = CarbonImmutable::now();
        $grace = $subscription->grace_days ?? app(PlatformSettings::class)->get('grace_days');
        $trialEnds = $subscription->trial_ends_at === null ? null : CarbonImmutable::instance($subscription->trial_ends_at);

        return [
            'status' => $subscription->status,
            'trial_ends_at' => $trialEnds?->toIso8601String(),
            // SUB-03: subscribed during the trial; the first paid period starts at the trial end.
            'subscribed' => $subscription->status !== Subscription::TRIAL || $subscription->hasPeriod(),
            'first_period_start' => $subscription->status === Subscription::TRIAL ? $subscription->current_period_start?->toDateString() : null,
            'trial_days_left' => $subscription->status === Subscription::TRIAL && $trialEnds !== null ? max(0, (int) ceil($now->diffInSeconds($trialEnds, false) / 86400)) : null,
            'past_due_since' => $subscription->past_due_since?->toIso8601String(),
            'read_only_on' => $subscription->status === Subscription::PAST_DUE && $subscription->past_due_since !== null
                ? CarbonImmutable::instance($subscription->past_due_since)->addDays($grace)->toIso8601String() : null,
            'read_only_since' => $subscription->read_only_since?->toIso8601String(),
            'pos_stops_at' => $subscription->pos_stops_at?->toIso8601String(),
        ];
    }

    /** @param array{date: ?string, amount_minor: ?int} $next */
    public static function subscription(Subscription $subscription, array $next): array
    {
        $money = fn (?int $minor) => $minor === null ? null : ['amount_minor' => $minor, 'currency' => $subscription->currency];

        return [
            'id' => $subscription->id,
            ...self::status($subscription),
            'country' => $subscription->country,
            'currency' => $subscription->currency,
            'cycle' => $subscription->cycle,
            'bundle' => $subscription->bundle,
            'modules' => array_values($subscription->modules ?? []),
            'quantities' => (object) ($subscription->quantities ?? []),
            'coupon_code' => $subscription->coupon_code,
            'current_period_start' => $subscription->current_period_start?->toDateString(),
            'current_period_end' => $subscription->current_period_end?->toDateString(),
            'period_amount' => $money($subscription->period_amount_minor),
            'monthly_amount' => $money($subscription->monthly_amount_minor),
            'pending_change' => $subscription->pending_change === null ? null : [
                'modules' => $subscription->pending_change['modules'] ?? [],
                'quantities' => (object) ($subscription->pending_change['quantities'] ?? []),
                'cycle' => $subscription->pending_change['cycle'] ?? $subscription->cycle,
                'bundle' => $subscription->pending_change['bundle'] ?? null,
                'effective_on' => $subscription->current_period_end?->toDateString(),
            ],
            'next_invoice' => ['date' => $next['date'], 'amount' => $money($next['amount_minor'])],
            'default_payment_method_id' => $subscription->default_payment_method_id,
            'wallets' => (array) config("billing.wallets.{$subscription->country}", []),
            // The checkout behind "card": Paystack (M-Pesa or card, a card is saved by paying), else a hosted page.
            'checkout_provider' => (string) config('billing.drivers.card_aggregator'),
            'card_setup' => config('billing.drivers.card_aggregator') !== 'paystack',
        ];
    }

    public static function method(BillingPaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'kind' => $method->kind,
            'provider' => $method->provider,
            'label' => $method->label,
            'is_default' => $method->is_default,
            'auto_renew' => $method->auto_renew,
            'archived_at' => $method->archived_at?->toIso8601String(),
        ];
    }

    public static function payment(BillingPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'invoice_id' => $payment->billing_invoice_id,
            'provider' => $payment->provider,
            'label' => $payment->label,
            'amount' => ['amount_minor' => $payment->amount_minor, 'currency' => $payment->currency],
            'status' => $payment->status,
            'redirect_url' => $payment->redirect_url,
            'message' => $payment->message,
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }
}
