<?php

namespace App\Core\Billing\Dunning;

use App\Core\Billing\Catalogue\Catalogue;
use App\Core\Billing\Invoicing\Invoicing;
use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Accounts;
use App\Core\Billing\Overage\OveragePricing;
use App\Core\Billing\Payments\BillingPayments;
use App\Core\Billing\PlatformSettings;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * SUB-03, SUB-05: one tenant's billing clock, run daily by
 * `billing:cycle` in the tenant's context (and on demand by the Owner's
 * "Subscribe now").
 *
 * - Reminders 7 and 3 days before the due date (the trial end, or the
 *   period end), and on the due date (`billing.reminder_days`); each once.
 * - On the due date the next period starts: a change waiting for the
 *   period end applies, prices are those of the day (then locked for the
 *   period), overage of the period that ended is added (LIM-05), and the
 *   invoice is issued (the first one after a trial, else a renewal). A
 *   default saved method requests it automatically.
 * - An invoice unpaid after its due date makes the subscription past due;
 *   after the grace period (platform setting, or the tenant's override)
 *   it is read-only, and the POS keeps uploading sales for the POS window.
 * - A read-only subscription is not renewed: no new invoice until it is
 *   paid (SUB-06 restores it at once).
 *
 * LIM-04: approvals are never blocked, by limits or by dunning (they stay
 * open in read-only, EnforceSubscription); sales from the POS continue
 * for the window.
 */
class BillingCycle
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly Invoicing $invoicing,
        private readonly BillingPayments $payments,
        private readonly BillingNotices $notices,
        private readonly PlatformSettings $settings,
        private readonly OveragePricing $overage,
        private readonly Catalogue $catalogue,
        private readonly Accounts $accounts,
    ) {}

    public function run(CarbonImmutable $at): void
    {
        $subscription = $this->subscriptions->current();

        if ($subscription === null || in_array($subscription->status, [Subscription::SUSPENDED, Subscription::CANCELLED], true)) {
            return;
        }

        $this->remind($subscription, $at);
        $invoice = $this->renew($subscription, $at);

        // SUB-04: a saved default method requests the new invoice before dunning looks at it.
        if ($invoice !== null) {
            $this->payments->autoRenew($subscription->fresh(), $invoice);
        }

        $this->dun($subscription->fresh(), $at);
        $this->accounts->sync($subscription->fresh());
    }

    /**
     * The Owner subscribes during the trial: the first invoice is issued
     * now for a period starting today; it falls due when the trial ends.
     */
    public function subscribeNow(Subscription $subscription, CarbonImmutable $at): BillingInvoice
    {
        if ($subscription->status !== Subscription::TRIAL || $subscription->hasPeriod()) {
            throw new ApiException(422, 'already_subscribed', __('billing.errors.already_subscribed'));
        }

        $due = $subscription->trial_ends_at === null ? $at : CarbonImmutable::instance($subscription->trial_ends_at)->max($at);
        $invoice = $this->startPeriod($subscription, $at->utc()->startOfDay(), $due, 'first');
        $this->accounts->sync($subscription->fresh());

        return $invoice;
    }

    private function remind(Subscription $subscription, CarbonImmutable $at): void
    {
        $due = $subscription->nextDueAt();

        if ($due === null || $at->greaterThanOrEqualTo($due)) {
            return;
        }

        foreach ((array) config('billing.reminder_days', [7, 3, 0]) as $days) {
            $days = (int) $days;

            if ($days === 0 || $at->lessThan($due->subDays($days))) {
                continue;
            }

            $this->notifyOnce($subscription, $due, $days);
        }
    }

    private function notifyOnce(Subscription $subscription, CarbonImmutable $due, int $days): void
    {
        $key = $due->toDateString().':'.$days;
        $sent = $subscription->reminders_sent ?? [];

        if (in_array($key, $sent, true)) {
            return;
        }

        $trial = ! $subscription->hasPeriod() && $subscription->status === Subscription::TRIAL;
        $this->notices->send($trial ? BillingNotices::TRIAL_ENDING : BillingNotices::RENEWAL_DUE, [
            'days' => (string) $days,
            'date' => BillingNotices::date($due),
            'amount' => $this->notices->money($this->nextAmount($subscription, $due), $subscription->currency),
        ]);

        $subscription->forceFill(['reminders_sent' => array_slice([...$sent, $key], -12)])->saveQuietly();
    }

    /** @return array{date: ?string, amount_minor: ?int} the next invoice's due date and amount */
    public function nextInvoice(Subscription $subscription): array
    {
        $due = $subscription->nextDueAt();

        return ['date' => $due?->toDateString(), 'amount_minor' => $due === null ? null : $this->nextAmount($subscription, $due)];
    }

    /** What the next invoice will come to: the plan due next at the day's prices. */
    public function nextAmount(Subscription $subscription, CarbonImmutable $due): int
    {
        $open = BillingInvoice::query()->where('status', BillingInvoice::OPEN)->where('period_start', $due->toDateString())->value('total_minor');

        if ($open !== null) {
            return (int) $open;
        }

        $plan = $subscription->pending_change === null ? $this->subscriptions->currentPlan($subscription) : Subscriptions::normalise($subscription->pending_change);

        try {
            $coupon = $subscription->coupon_until === null ? $this->subscriptions->coupon($subscription, nextPeriod: true) : null;

            return (int) $this->subscriptions->price($subscription->country, $plan, [], $coupon)['total'];
        } catch (ApiException) {
            return $subscription->period_amount_minor;
        }
    }

    private function renew(Subscription $subscription, CarbonImmutable $at): ?BillingInvoice
    {
        $due = $subscription->nextDueAt();

        if ($due === null || $at->lessThan($due) || $subscription->status === Subscription::READ_ONLY) {
            return null;
        }

        // An invoice issued late (a catch-up after the period ended, or after a reactivation)
        // falls due when it is issued, never in the past: the grace period starts from there.
        $payBy = $due->max($at);
        $invoice = $this->startPeriod($subscription, $due->startOfDay(), $payBy, $subscription->hasPeriod() ? 'renewal' : 'first');

        $this->notifyOnce($subscription->fresh(), $due, 0);

        return $invoice;
    }

    /** Start the period beginning on $start and invoice it, due at $due. */
    private function startPeriod(Subscription $subscription, CarbonImmutable $start, CarbonImmutable $due, string $kind): BillingInvoice
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($subscription, $start, $due, $kind) {
            $subscription = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $overage = [];

            if ($kind === 'renewal' && $subscription->hasPeriod()) {
                $from = CarbonImmutable::parse($subscription->current_period_start->toDateString(), 'UTC');
                $to = CarbonImmutable::parse($subscription->current_period_end->toDateString(), 'UTC');
                $overage = $this->overage->forPeriod($subscription, $this->catalogue->snapshot($subscription->country, $from->toDateString(), $subscription->price_ids ?? []), $from, $to);
                // Charged once: the period is marked with the invoice that carries it (saved with the plan below).
                $this->overage->billed($subscription, $to);
            }

            // A coupon applies to the period it was redeemed for.
            if ($subscription->coupon_id !== null && $subscription->coupon_until !== null && $subscription->coupon_until->toDateString() < $start->toDateString()) {
                $subscription->fill(['coupon_id' => null, 'coupon_code' => null, 'coupon_until' => null]);
            }

            $plan = $subscription->pending_change === null ? $this->subscriptions->currentPlan($subscription) : Subscriptions::normalise($subscription->pending_change);
            $quote = $this->subscriptions->price($subscription->country, $plan, [], $this->subscriptions->coupon($subscription, nextPeriod: true), $start);
            $end = Subscriptions::periodEnd($start, $plan['cycle']);

            $subscription->fill([
                'current_period_start' => $start->toDateString(),
                'current_period_end' => $end->toDateString(),
                'coupon_until' => $subscription->coupon_id !== null && $subscription->coupon_until === null ? $end->subDay()->toDateString() : $subscription->coupon_until,
            ]);
            $this->subscriptions->applyPlan($subscription, $plan, $quote);

            return $this->invoicing->issue($subscription, $kind, $this->invoicing->linesFromQuote($quote, $overage), $start, $end, $due);
        });
    }

    private function dun(Subscription $subscription, CarbonImmutable $at): void
    {
        // Overdue once a whole day has passed since it fell due (the due date itself is for paying).
        $overdue = BillingInvoice::query()->where('status', BillingInvoice::OPEN)->where('due_at', '<=', $at->subDay())->orderBy('due_at')->first();
        $grace = $subscription->grace_days ?? $this->settings->get('grace_days');

        if ($overdue !== null && in_array($subscription->status, [Subscription::TRIAL, Subscription::ACTIVE], true)) {
            $since = CarbonImmutable::instance($overdue->due_at);
            $subscription->fill(['status' => Subscription::PAST_DUE, 'past_due_since' => $since])->save();
            $this->notices->send(BillingNotices::PAST_DUE, [
                'amount' => $this->notices->money($overdue->total_minor, $overdue->currency),
                'number' => $overdue->number,
                'date' => BillingNotices::date($overdue->due_at),
                'read_only_date' => BillingNotices::date($since->addDays($grace)),
            ]);
        }

        if ($overdue === null && $subscription->status === Subscription::PAST_DUE) {
            $subscription->fill(['status' => Subscription::ACTIVE, 'past_due_since' => null])->save();

            return;
        }

        if ($overdue !== null && $subscription->status === Subscription::PAST_DUE && $subscription->past_due_since !== null
            && $at->greaterThanOrEqualTo(CarbonImmutable::instance($subscription->past_due_since)->addDays($grace))) {
            $posUntil = $at->addDays($this->settings->get('pos_window_days'));
            $subscription->fill(['status' => Subscription::READ_ONLY, 'read_only_since' => $at, 'pos_stops_at' => $posUntil])->save();
            $this->notices->send(BillingNotices::READ_ONLY, [
                'amount' => $this->notices->money($overdue->total_minor, $overdue->currency),
                'number' => $overdue->number,
                'date' => BillingNotices::date($overdue->due_at),
                'pos_until' => BillingNotices::date($posUntil),
            ]);
        }
    }
}
