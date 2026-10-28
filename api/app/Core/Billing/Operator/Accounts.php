<?php

namespace App\Core\Billing\Operator;

use App\Core\Billing\Catalogue\Models\CatalogueBundle;
use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\OperatorAccount;
use App\Core\Billing\Models\OperatorAccountEvent;
use App\Core\Billing\Models\Subscription;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\Models\TenantModule;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The operator's record of each customer (OperatorAccount), kept in step
 * with the subscription by the billing service: read in the customer's
 * context, written in the operator's. Staff list tenants from it, never
 * from the customers' own tables. Skipped while no operator exists
 * (environments not selling yet).
 */
class Accounts
{
    /** Statuses of an account that pays (ADM-06: churn is leaving one of them). */
    public const PAYING = [Subscription::ACTIVE, Subscription::PAST_DUE, Subscription::READ_ONLY];

    public function __construct(
        private readonly Operator $operator,
        private readonly TenantContext $tenants,
    ) {}

    /** Mirror the current (customer) tenant's subscription into the operator's account row. */
    public function sync(Subscription $subscription): void
    {
        if ($this->operator->id() === null || $this->operator->is($this->tenants->id())) {
            return;
        }

        $customerId = $this->tenants->require();
        $tenant = Tenant::query()->whereKey($customerId)->first(['name', 'slug']);
        $name = (string) $tenant?->name;
        $purgeDue = TenantModule::query()->whereNotNull('purge_flagged_at')->where('status', TenantModule::INACTIVE)->orderBy('module')
            ->get(['module', 'switched_off_at'])
            ->map(fn (TenantModule $m) => ['module' => $m->module, 'switched_off_at' => $m->switched_off_at?->toIso8601String()])
            ->values()->all();

        // SUB-03: a subscribed trial whose first invoice is paid stays a trial until the trial ends, yet it pays.
        $paidTrial = $subscription->status === Subscription::TRIAL && $subscription->hasPeriod()
            && BillingInvoice::query()->where('status', BillingInvoice::PAID)->exists();

        $values = [
            'name' => $name,
            'country' => $subscription->country,
            'currency' => $subscription->currency,
            'slug' => $tenant?->slug,
            'plan' => self::planLabel($subscription),
            'bundle' => $subscription->bundle,
            'modules' => self::coveredModules($subscription),
            'cycle' => $subscription->cycle,
            'status' => $subscription->status,
            'mrr_minor' => $subscription->status === Subscription::TRIAL && ! $paidTrial ? 0 : $subscription->monthly_amount_minor,
            'trial_ends_at' => $subscription->trial_ends_at,
            'current_period_end' => $subscription->current_period_end?->toDateString(),
            'grace_days' => $subscription->grace_days,
            'purge_due' => $purgeDue,
        ];

        $this->operator->run(function () use ($customerId, $values, $paidTrial) {
            DB::connection(TenantContext::CONNECTION)->transaction(function () use ($customerId, $values, $paidTrial) {
                $account = OperatorAccount::query()->where('customer_tenant_id', $customerId)->lockForUpdate()->first();
                $from = $account?->status;
                $fromMrr = (int) ($account?->mrr_minor ?? 0);
                OperatorAccount::query()->updateOrCreate(['customer_tenant_id' => $customerId], $values);
                $this->recordEvent($customerId, $from, $fromMrr, $values, $paidTrial);
            });
        });
    }

    /**
     * ADM-06: the account's status change as a report event (operator
     * context). Churn: a paying account becoming cancelled or suspended;
     * a conversion: once, when the trial's first invoice is paid (a
     * subscribed trial converts then, though it stays a trial until its
     * end) or, failing that, when the account first becomes active. A paid
     * subscribed trial counts as paying, so leaving it is churn.
     *
     * @param  array<string, mixed>  $values
     */
    private function recordEvent(string $customerId, ?string $from, int $fromMrr, array $values, bool $paidTrial = false): void
    {
        $to = (string) $values['status'];
        $converted = fn (): bool => OperatorAccountEvent::query()->where('customer_tenant_id', $customerId)
            ->whereIn('kind', [OperatorAccountEvent::CONVERTED, OperatorAccountEvent::SUBSCRIBED])->exists();

        if ($from === $to) {
            // Same status: only a trial that has just paid its first invoice is an event.
            if ($from === Subscription::TRIAL && $paidTrial && ! $converted()) {
                $this->storeEvent($customerId, OperatorAccountEvent::CONVERTED, $from, $values, (int) $values['mrr_minor']);
            }

            return;
        }

        $wasPaying = in_array($from, self::PAYING, true) || ($from === Subscription::TRIAL && $fromMrr > 0);

        $kind = match (true) {
            $from === null => $to === Subscription::TRIAL ? OperatorAccountEvent::TRIAL_STARTED : OperatorAccountEvent::SUBSCRIBED,
            in_array($to, [Subscription::CANCELLED, Subscription::SUSPENDED], true) && $wasPaying => OperatorAccountEvent::CHURNED,
            $to === Subscription::SUSPENDED => OperatorAccountEvent::SUSPENDED,
            in_array($from, [Subscription::CANCELLED, Subscription::SUSPENDED], true) => OperatorAccountEvent::REACTIVATED,
            $to === Subscription::ACTIVE && ! $converted() => OperatorAccountEvent::CONVERTED,
            default => null,
        };

        if ($kind === null) {
            return;
        }

        $this->storeEvent($customerId, $kind, $from, $values, $kind === OperatorAccountEvent::CHURNED ? $fromMrr : (int) $values['mrr_minor']);
    }

    /** @param  array<string, mixed>  $values */
    private function storeEvent(string $customerId, string $kind, ?string $from, array $values, int $mrr): void
    {
        OperatorAccountEvent::query()->create([
            'customer_tenant_id' => $customerId,
            'kind' => $kind,
            'from_status' => $from,
            'to_status' => (string) $values['status'],
            'country' => $values['country'],
            'currency' => $values['currency'],
            'bundle' => $values['bundle'],
            'mrr_minor' => $mrr,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    /** Accounts that pay: the paying statuses, and a trial that has paid its first invoice (its MRR is set). */
    public static function paying(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereIn('status', self::PAYING)
            ->orWhere(fn (Builder $t) => $t->where('status', Subscription::TRIAL)->where('mrr_minor', '>', 0)));
    }

    /**
     * The modules the plan covers: its own and its bundle's, sorted.
     *
     * @return list<string>
     */
    public static function coveredModules(Subscription $subscription): array
    {
        $inBundle = $subscription->bundle === null ? [] : (CatalogueBundle::on(PlatformDatabase::READ)->where('key', $subscription->bundle)->first()?->modules ?? []);
        $modules = array_values(array_unique([...($subscription->modules ?? []), ...$inBundle]));
        sort($modules);

        return $modules;
    }

    /** "starter + payroll", "pos + inventory": the bundle then the modules outside it, by key. */
    public static function planLabel(Subscription $subscription): string
    {
        $inBundle = $subscription->bundle === null ? [] : (CatalogueBundle::on(PlatformDatabase::READ)->where('key', $subscription->bundle)->first()?->modules ?? []);
        $parts = $subscription->bundle === null ? [] : [$subscription->bundle];

        return mb_substr(implode(' + ', [...$parts, ...array_values(array_diff($subscription->modules ?? [], $inBundle))]), 0, 255);
    }
}
