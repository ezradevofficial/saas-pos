<?php

namespace App\Core\Billing\Subscriptions;

use App\Core\Audit\Auditor;
use App\Core\Billing\Catalogue\Catalogue;
use App\Core\Billing\Catalogue\Models\CatalogueBundle;
use App\Core\Billing\Catalogue\Models\CatalogueCoupon;
use App\Core\Billing\Catalogue\Models\CatalogueModule;
use App\Core\Billing\Catalogue\Pricing\CatalogueSnapshot;
use App\Core\Billing\Catalogue\Pricing\Coupon;
use App\Core\Billing\Catalogue\Pricing\PriceCalculator;
use App\Core\Billing\Catalogue\Pricing\QuoteError;
use App\Core\Billing\Catalogue\Pricing\QuoteRequest;
use App\Core\Billing\Invoicing\Invoicing;
use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Accounts;
use App\Core\Billing\PlatformSettings;
use App\Core\Billing\Rounding;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Limits\UsageLimits;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * SUB-02, SUB-07: the current tenant's subscription.
 *
 * - A plan is a selection the PriceCalculator prices: modules, quantities
 *   per unit, cycle, bundle (and a coupon). The period is priced at the
 *   catalogue rows it was sold at (`price_ids`); items added mid-period take
 *   today's price, kept for the rest of the period.
 * - Every change is quoted first (quote()), then confirmed with the amount
 *   shown (change()); a confirm whose amount no longer matches is refused
 *   with the fresh quote (409 `quote_changed`).
 * - During a trial (no paid period yet) a change applies at once, free.
 * - Costing more: applied at once; the difference for the days left is
 *   invoiced now (an `upgrade` invoice; PriceCalculator::prorate, by day,
 *   rounded half up to `billing.rounding_minor`). A change that adds and
 *   removes items is judged on its total.
 * - Costing the same or less, or a change of cycle: kept in
 *   `pending_change` and applied at the period end (the renewal).
 * - The module registry follows the plan: subscribed modules on, the
 *   others off (SUB-07: hidden, data kept).
 *
 * Runs in the subscribing tenant's context; every change is audited.
 */
class Subscriptions
{
    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly PriceCalculator $calculator,
        private readonly ModuleRegistry $modules,
        private readonly TenantContext $tenants,
        private readonly PlatformSettings $settings,
        private readonly Accounts $accounts,
        private readonly Invoicing $invoicing,
        private readonly Auditor $auditor,
    ) {}

    public function current(): ?Subscription
    {
        return Subscription::query()->first();
    }

    public function require(): Subscription
    {
        return $this->current() ?? throw new ApiException(404, 'no_subscription', __('billing.errors.no_subscription'));
    }

    /**
     * ONB-04: start the trial (no payment needed) with $plan; the plan's
     * modules are switched on. Idempotent: a tenant with a subscription
     * keeps it.
     *
     * @param  array{modules?: list<string>, quantities?: array<string, int>, cycle?: string, bundle?: ?string}  $plan
     */
    public function startTrial(string $country, array $plan, ?int $days = null): Subscription
    {
        $existing = $this->current();

        if ($existing !== null) {
            return $existing;
        }

        $plan = self::normalise($plan);
        $quote = $this->price($country, $plan, [], null);
        $days ??= $this->settings->get('trial_days');

        $subscription = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($country, $plan, $quote, $days) {
            $subscription = Subscription::create([
                'country' => $country,
                'currency' => $quote['currency'],
                ...$plan,
                'price_ids' => $quote['price_ids'],
                'status' => Subscription::TRIAL,
                'trial_ends_at' => CarbonImmutable::now()->addDays($days),
                'period_amount_minor' => (int) $quote['total'],
                'monthly_amount_minor' => self::monthly($quote),
            ]);
            $this->syncModules($subscription);

            return $subscription;
        });

        $this->accounts->sync($subscription);

        return $subscription;
    }

    /**
     * Price $plan in $country: today's catalogue, with $locked rows kept.
     *
     * @param  array<string, string>  $locked
     * @return array<string, mixed> the quote, plus `price_ids` (the rows it used)
     */
    public function price(string $country, array $plan, array $locked, ?Coupon $coupon, ?CarbonImmutable $on = null): array
    {
        $snapshot = $this->catalogue->snapshot($country, ($on ?? CarbonImmutable::now())->toDateString(), $locked);

        try {
            $quote = $this->calculator->quote($snapshot, new QuoteRequest($plan['modules'], $plan['quantities'], $plan['cycle'], $plan['bundle'], $coupon));
        } catch (QuoteError $e) {
            $name = $e->key === null ? '' : (CatalogueModule::on(PlatformDatabase::READ)->where('key', $e->key)->first()?->localName() ?? CatalogueBundle::on(PlatformDatabase::READ)->where('key', $e->key)->first()?->localName() ?? $e->key);
            $message = __("billing.quote.{$e->reason}", ['name' => $name]);

            throw new ApiException(422, $e->reason, $message, [$e->field => [$message]], $e->suggestion === null ? [] : ['suggestion' => $e->suggestion]);
        }

        if ($quote['quoted']) {
            throw new ApiException(422, 'plan_quoted', __('billing.errors.plan_quoted'), ['bundle' => [__('billing.errors.plan_quoted')]]);
        }

        // SUB-04: what is charged is whole steps of the currency (KES: whole shillings for M-Pesa).
        if ((int) ($quote['coupon_discount'] ?? 0) > 0) {
            $discount = Rounding::round((int) $quote['coupon_discount'], $quote['currency']);
            $quote['coupon_discount'] = $discount;
            $quote['coupon']['discount'] = $discount;
        }

        $quote['total'] = Rounding::round(max(0, (int) $quote['subtotal'] - (int) $quote['annual_discount'] - (int) $quote['coupon_discount']), $quote['currency']);
        $quote['price_ids'] = self::priceIds($snapshot, $quote);
        $quote['snapshot'] = $snapshot;

        return $quote;
    }

    /**
     * The quote of changing to $plan (and optionally adding a coupon) at
     * $at, without changing anything.
     *
     * @return array<string, mixed>
     */
    public function quote(Subscription $subscription, array $plan, ?string $couponCode = null, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $plan = self::normalise($plan);
        $coupon = $this->coupon($subscription);

        if ($couponCode !== null && trim($couponCode) !== '') {
            if ($subscription->coupon_id !== null) {
                throw new ApiException(422, 'coupon_already_applied', __('billing.errors.coupon_already_applied'), ['coupon' => [__('billing.errors.coupon_already_applied')]]);
            }

            $coupon = $this->catalogue->coupon($couponCode);
        }

        $current = $this->price($subscription->country, $this->currentPlan($subscription), $subscription->price_ids ?? [], $this->coupon($subscription), $at);
        $new = $this->price($subscription->country, $plan, $subscription->price_ids ?? [], $coupon, $at);

        if ($coupon !== null && $couponCode !== null && trim($couponCode) !== '' && ! ($new['coupon']['applied'] ?? false)) {
            throw new ApiException(422, 'coupon_not_applicable', __('billing.errors.coupon_not_applicable'), ['coupon' => [__('billing.errors.coupon_not_applicable')]]);
        }

        $same = self::samePlan($subscription, $plan) && ($couponCode === null || trim($couponCode) === '');
        [$periodDays, $remaining] = $this->days($subscription, $at);
        $charge = 0;

        // A coupon added during a paid period applies from the next period (with any change made with it).
        $couponNow = $couponCode !== null && trim($couponCode) !== '';

        $kind = match (true) {
            $same => 'none',
            ! $subscription->hasPeriod() => 'trial',
            $couponNow => 'scheduled',
            $plan['cycle'] !== $subscription->cycle => 'scheduled',
            $new['total'] > $current['total'] => 'upgrade',
            default => 'scheduled',
        };

        if ($kind === 'upgrade') {
            $charge = $this->calculator->prorate((int) $current['total'], (int) $new['total'], $periodDays, $remaining, (int) config("billing.rounding_minor.{$subscription->currency}", 1));
        }

        return [
            'kind' => $kind,
            'effective' => in_array($kind, ['trial', 'upgrade'], true) ? 'now' : ($kind === 'none' ? null : 'period_end'),
            'effective_on' => $kind === 'scheduled' ? $subscription->current_period_end?->toDateString() : ($kind === 'none' ? null : $at->toDateString()),
            'currency' => $subscription->currency,
            'charge_now' => $charge,
            'period_days' => $periodDays,
            'remaining_days' => $remaining,
            'coupon' => $new['coupon'],
            'current' => self::publicQuote($current),
            'new' => self::publicQuote($new),
            'plan' => $plan,
            '_new' => $new,
        ];
    }

    /**
     * Confirm a change quoted for $expectedCharge (the amount the Owner
     * saw). Applied now or scheduled for the period end as quote() says;
     * an upgrade's charge is invoiced at once.
     *
     * @return array{quote: array<string, mixed>, subscription: Subscription, invoice: ?BillingInvoice}
     */
    public function change(Subscription $subscription, array $plan, ?string $couponCode, int $expectedCharge, User $by): array
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($subscription, $plan, $couponCode, $expectedCharge, $by) {
            $subscription = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $quote = $this->quote($subscription, $plan, $couponCode);

            if ($quote['kind'] === 'none') {
                throw new ApiException(422, 'no_change', __('billing.errors.no_change'));
            }

            if ($quote['charge_now'] !== $expectedCharge) {
                throw new ApiException(409, 'quote_changed', __('billing.errors.quote_changed'), [], ['quote' => self::withoutInternals($quote)]);
            }

            $before = ['plan' => $subscription->plan(), 'pending_change' => $subscription->pending_change];
            $new = $quote['_new'];
            $invoice = null;

            if ($couponCode !== null && trim($couponCode) !== '') {
                $this->redeem($subscription, $couponCode);
            }

            if ($quote['effective'] === 'now') {
                $this->applyPlan($subscription, $quote['plan'], $new);

                if ($quote['kind'] === 'upgrade' && $quote['charge_now'] > 0) {
                    $now = CarbonImmutable::now();
                    $invoice = $this->invoicing->issue(
                        $subscription,
                        'upgrade',
                        $this->invoicing->prorationLines($quote['charge_now'], $quote['remaining_days'], $quote['period_days']),
                        $now->startOfDay(),
                        CarbonImmutable::parse($subscription->current_period_end->toDateString(), 'UTC'),
                        $now,
                    );
                }
            } else {
                $subscription->fill(['pending_change' => [...$quote['plan'], 'requested_at' => CarbonImmutable::now()->toIso8601String(), 'requested_by' => $by->id]])->save();
            }

            $this->auditor->record('core.subscription.change', $subscription, $before, [
                'plan' => $quote['plan'],
                'kind' => $quote['kind'],
                'effective' => $quote['effective'],
                'charge_now' => $quote['charge_now'],
                'currency' => $quote['currency'],
                'invoice' => $invoice?->number,
            ]);

            DB::connection(TenantContext::CONNECTION)->afterCommit(fn () => $this->accounts->sync($subscription->fresh()));

            return ['quote' => self::withoutInternals($quote), 'subscription' => $subscription->fresh(), 'invoice' => $invoice];
        });
    }

    /** Drop a change waiting for the period end. */
    public function cancelPending(Subscription $subscription): Subscription
    {
        if ($subscription->pending_change !== null) {
            $subscription->fill(['pending_change' => null])->save();
        }

        return $subscription;
    }

    /**
     * Put $plan into effect now with its quote: the plan, the rows it is
     * priced at and its amounts; the module registry follows.
     *
     * @param  array<string, mixed>  $quote
     */
    public function applyPlan(Subscription $subscription, array $plan, array $quote): void
    {
        $subscription->fill([
            'modules' => $plan['modules'],
            'quantities' => $plan['quantities'],
            'cycle' => $plan['cycle'],
            'bundle' => $plan['bundle'],
            'price_ids' => $quote['price_ids'],
            'period_amount_minor' => (int) $quote['total'],
            'monthly_amount_minor' => self::monthly($quote),
            'pending_change' => null,
        ])->save();

        $this->syncModules($subscription);

        // LIM-01..LIM-03: meter against the new allowances once the change commits, so an
        // overage the plan now covers closes and adding is unblocked at once.
        $tenantId = $this->tenants->require();
        DB::connection(TenantContext::CONNECTION)->afterCommit(fn () => $this->tenants->run($tenantId, fn () => app(UsageLimits::class)->meter(CarbonImmutable::now())));
    }

    /**
     * SUB-07, RBAC-08: the registry follows the plan. Registered modules the
     * catalogue sells are switched on when in the plan (or its bundle) and
     * off otherwise, their data kept (re-subscribing restores it).
     */
    public function syncModules(Subscription $subscription): void
    {
        $subscribed = $subscription->modules ?? [];

        if ($subscription->bundle !== null) {
            $subscribed = [...$subscribed, ...($this->catalogue->snapshot($subscription->country)->bundle($subscription->bundle)?->modules ?? [])];
        }

        // Only modules the catalogue sells follow the plan; others are left as they are.
        $sold = CatalogueModule::on(PlatformDatabase::READ)->pluck('key')->all();

        foreach ($this->modules->registered() as $module) {
            if ($module === ModuleRegistry::CORE || ! in_array($module, $sold, true)) {
                continue;
            }

            $wanted = in_array($module, $subscribed, true);
            $active = $this->modules->isActive($module);

            if ($wanted && ! $active) {
                $this->modules->activate($module);
            } elseif (! $wanted && $active) {
                $this->modules->deactivate($module);
            }
        }
    }

    /**
     * SUB-06: an invoice of the subscription was paid. With no other
     * invoice overdue, the subscription is active again at once (from
     * trial, past due or read-only).
     */
    public function paid(Subscription $subscription, BillingInvoice $invoice): Subscription
    {
        $overdue = BillingInvoice::query()->where('status', BillingInvoice::OPEN)->where('due_at', '<=', CarbonImmutable::now()->subDay())->exists();

        if (! $overdue && in_array($subscription->status, [Subscription::TRIAL, Subscription::PAST_DUE, Subscription::READ_ONLY], true)) {
            $subscription->fill([
                'status' => Subscription::ACTIVE,
                'past_due_since' => null,
                'read_only_since' => null,
                'pos_stops_at' => null,
            ])->save();
        }

        $this->accounts->sync($subscription);

        return $subscription;
    }

    /**
     * The coupon that prices the current period (or the trial's plan); a
     * coupon redeemed during a paid period waits for the next one.
     */
    public function coupon(Subscription $subscription, bool $nextPeriod = false): ?Coupon
    {
        if ($subscription->coupon_id === null || (! $nextPeriod && $subscription->hasPeriod() && $subscription->coupon_until === null)) {
            return null;
        }

        return CatalogueCoupon::on(PlatformDatabase::READ)->find($subscription->coupon_id)?->toPricing();
    }

    /**
     * Count one redemption of $code against its limit and attach it to the
     * subscription; refused when used up, expired or archived meanwhile.
     */
    private function redeem(Subscription $subscription, string $code): void
    {
        $coupon = CatalogueCoupon::on(PlatformDatabase::READ)->where('code', trim($code))->first();
        // ADR 013: only the platform role writes the catalogue; the count is guarded by the limit in the same statement.
        $counted = $coupon === null ? 0 : PlatformDatabase::connection()->table('catalogue_coupons')
            ->where('id', $coupon->id)
            ->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now()))
            ->where(fn ($q) => $q->whereNull('max_redemptions')->orWhereColumn('redemptions', '<', 'max_redemptions'))
            ->increment('redemptions');

        if ($counted === 0) {
            throw new ApiException(422, 'coupon_invalid', __('billing.coupon.invalid'), ['coupon' => [__('billing.coupon.invalid')]]);
        }

        // The redemption commits on its own connection: give it back if the change does not commit.
        DB::connection(TenantContext::CONNECTION)->afterRollBack(fn () => PlatformDatabase::connection()->table('catalogue_coupons')
            ->where('id', $coupon->id)->where('redemptions', '>', 0)->decrement('redemptions'));

        // Its period is set when one starts (the first after a trial, or the next after a paid one).
        $subscription->fill([
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'coupon_until' => null,
        ])->save();
    }

    /** @return array{0: int, 1: int} days in the current period, and days left at $at (both 0 without a period) */
    public function days(Subscription $subscription, CarbonImmutable $at): array
    {
        if (! $subscription->hasPeriod()) {
            return [0, 0];
        }

        $start = CarbonImmutable::parse($subscription->current_period_start->toDateString(), 'UTC');
        $end = CarbonImmutable::parse($subscription->current_period_end->toDateString(), 'UTC');
        $today = $at->utc()->startOfDay();
        $periodDays = (int) $start->diffInDays($end);
        $remaining = (int) max(0, $today->max($start)->diffInDays($end, false));

        return [$periodDays, min($periodDays, $remaining)];
    }

    /** The end (exclusive) of a period starting on $start. */
    public static function periodEnd(CarbonImmutable $start, string $cycle): CarbonImmutable
    {
        return $cycle === QuoteRequest::ANNUAL ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{modules: list<string>, quantities: array<string, int>, cycle: string, bundle: ?string}
     */
    public static function normalise(array $plan): array
    {
        $quantities = [];

        foreach ((array) ($plan['quantities'] ?? []) as $unit => $quantity) {
            $quantities[(string) $unit] = max(0, (int) $quantity);
        }

        ksort($quantities);
        $modules = array_values(array_unique(array_map('strval', (array) ($plan['modules'] ?? []))));
        sort($modules);

        return [
            'modules' => $modules,
            'quantities' => $quantities,
            'cycle' => (string) ($plan['cycle'] ?? QuoteRequest::MONTHLY),
            'bundle' => isset($plan['bundle']) && $plan['bundle'] !== '' ? (string) $plan['bundle'] : null,
        ];
    }

    /** @return array{modules: list<string>, quantities: array<string, int>, cycle: string, bundle: ?string} */
    public function currentPlan(Subscription $subscription): array
    {
        return self::normalise(['modules' => $subscription->modules, 'quantities' => $subscription->quantities ?? [], 'cycle' => $subscription->cycle, 'bundle' => $subscription->bundle]);
    }

    private static function samePlan(Subscription $subscription, array $plan): bool
    {
        return self::normalise(['modules' => $subscription->modules, 'quantities' => $subscription->quantities ?? [], 'cycle' => $subscription->cycle, 'bundle' => $subscription->bundle]) == $plan;
    }

    /** @return array<string, string> "module:key" / "bundle:key" => price row id of each priced line */
    private static function priceIds(CatalogueSnapshot $snapshot, array $quote): array
    {
        $ids = [];

        foreach ($quote['lines'] as $line) {
            $offer = $line['type'] === 'bundle' ? $snapshot->bundle($line['key']) : $snapshot->module($line['key']);

            if ($offer?->priceId !== null) {
                $ids["{$line['type']}:{$line['key']}"] = $offer->priceId;
            }
        }

        ksort($ids);

        return $ids;
    }

    /** MRR: the period total spread over its months, in minor units. */
    public static function monthly(array $quote): int
    {
        return intdiv((int) $quote['total'], max(1, (int) ($quote['months'] ?? 1)));
    }

    /** A quote as the API shows it: without the snapshot and price ids. */
    public static function publicQuote(array $quote): array
    {
        unset($quote['snapshot'], $quote['price_ids']);

        return $quote;
    }

    public static function withoutInternals(array $quote): array
    {
        unset($quote['_new']);

        return $quote;
    }
}
