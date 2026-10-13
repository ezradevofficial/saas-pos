<?php

namespace App\Core\Billing\Subscriptions;

use App\Core\Billing\Models\Subscription;
use App\Core\Http\ApiException;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Trial\TrialEnded;
use App\Core\Onboarding\Trial\TrialExpiry;
use App\Core\Onboarding\Trial\TrialSelection;
use App\Core\Onboarding\Trial\TrialStarter;
use App\Core\Onboarding\Trial\TrialStatus;
use App\Core\Onboarding\Trial\TrialStatusProvider;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ONB-04, SUB-02: the trial is the subscription's. Onboarding (sign-up's
 * module picker, assisted creation) starts it through TrialStarter: the
 * first selection opens the trial subscription; a later one, while the
 * trial runs and before any paid period, changes its plan at once (and the
 * modules follow). After the trial, plans change on Settings, Subscription.
 *
 * One trial end: `subscriptions.trial_ends_at`. It is copied to
 * `onboarding_states.trial_ends_at` (kept for onboarding's own screens and
 * its expiry run), here and when staff extend a trial (syncOnboarding).
 *
 * TrialExpiry does nothing: when a trial ends the billing cycle issues the
 * first invoice and dunning takes over; the subscribed modules stay on.
 */
class BillingTrial implements TrialExpiry, TrialStarter, TrialStatusProvider
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly TenantContext $tenants,
    ) {}

    public function start(TrialSelection $selection): TrialStatus
    {
        $plan = Subscriptions::normalise([
            'modules' => $selection->modules,
            'quantities' => $selection->quantities,
            'cycle' => $selection->cycle,
            'bundle' => $selection->bundle,
        ]);

        $subscription = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($selection, $plan) {
            $subscription = Subscription::query()->lockForUpdate()->first();

            if ($subscription === null) {
                return $this->subscriptions->startTrial($selection->country, $plan);
            }

            // Subscribed already ("Subscribe now" during the trial): the plan is the Subscription page's.
            if ($subscription->hasPeriod()) {
                throw new ApiException(409, 'already_subscribed', __('billing.errors.subscribed_change_plan'));
            }

            $running = $subscription->status === Subscription::TRIAL && ! $subscription->hasPeriod()
                && $subscription->trial_ends_at !== null && $subscription->trial_ends_at->isFuture();

            if (! $running) {
                throw TrialEnded::make();
            }

            $quote = $this->subscriptions->price($subscription->country, $plan, $subscription->price_ids ?? [], $this->subscriptions->coupon($subscription));
            $this->subscriptions->applyPlan($subscription, $plan, $quote);

            return $subscription->fresh();
        });

        self::syncOnboarding($subscription);

        return $this->statusOf($subscription);
    }

    public function status(): TrialStatus
    {
        return $this->statusOf(Subscription::query()->first());
    }

    /** Ended trials are the billing cycle's: the first invoice, then dunning. Modules stay on. */
    public function expire(): void {}

    /**
     * The trial as onboarding shows it: `trial` or `ended` while the
     * subscription is still on its trial; `none` once it has moved on (paid,
     * or past due and read-only, which the subscription banner shows).
     */
    private function statusOf(?Subscription $subscription): TrialStatus
    {
        if ($subscription === null) {
            return TrialStatus::none();
        }

        $endsAt = $subscription->trial_ends_at === null ? null : CarbonImmutable::instance($subscription->trial_ends_at);

        return $subscription->status === Subscription::TRIAL ? TrialStatus::until($endsAt) : TrialStatus::none();
    }

    /** Copy the trial's end to the onboarding record of the current tenant, when it has one. */
    public static function syncOnboarding(Subscription $subscription): void
    {
        $state = OnboardingState::query()->first();

        if ($state === null || $subscription->trial_ends_at === null) {
            return;
        }

        $endsAt = CarbonImmutable::instance($subscription->trial_ends_at);
        $state->fill([
            'trial_started_at' => $state->trial_started_at ?? $subscription->created_at,
            'trial_ends_at' => $endsAt,
            // An extended trial is running again: the expiry run may act once more.
            'trial_expired_at' => $endsAt->isFuture() ? null : $state->trial_expired_at,
        ])->save();
    }
}
