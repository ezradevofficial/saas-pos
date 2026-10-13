<?php

namespace App\Core\Billing\Operator;

use App\Core\Billing\Catalogue\Models\CatalogueBundle;
use App\Core\Billing\Models\OperatorAccount;
use App\Core\Billing\Models\Subscription;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\Models\TenantModule;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;

/**
 * The operator's record of each customer (OperatorAccount), kept in step
 * with the subscription by the billing service: read in the customer's
 * context, written in the operator's. Staff list tenants from it, never
 * from the customers' own tables. Skipped while no operator exists
 * (environments not selling yet).
 */
class Accounts
{
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
        $name = (string) Tenant::query()->whereKey($customerId)->value('name');
        $purgeDue = TenantModule::query()->whereNotNull('purge_flagged_at')->where('status', TenantModule::INACTIVE)->orderBy('module')
            ->get(['module', 'switched_off_at'])
            ->map(fn (TenantModule $m) => ['module' => $m->module, 'switched_off_at' => $m->switched_off_at?->toIso8601String()])
            ->values()->all();

        $values = [
            'name' => $name,
            'country' => $subscription->country,
            'currency' => $subscription->currency,
            'plan' => self::planLabel($subscription),
            'cycle' => $subscription->cycle,
            'status' => $subscription->status,
            'mrr_minor' => $subscription->status === Subscription::TRIAL ? 0 : $subscription->monthly_amount_minor,
            'trial_ends_at' => $subscription->trial_ends_at,
            'current_period_end' => $subscription->current_period_end?->toDateString(),
            'grace_days' => $subscription->grace_days,
            'purge_due' => $purgeDue,
        ];

        $this->operator->run(function () use ($customerId, $values) {
            OperatorAccount::query()->updateOrCreate(['customer_tenant_id' => $customerId], $values);
        });
    }

    /** "starter + payroll", "pos + inventory": the bundle then the modules outside it, by key. */
    public static function planLabel(Subscription $subscription): string
    {
        $inBundle = $subscription->bundle === null ? [] : (CatalogueBundle::on(PlatformDatabase::READ)->where('key', $subscription->bundle)->first()?->modules ?? []);
        $parts = $subscription->bundle === null ? [] : [$subscription->bundle];

        return mb_substr(implode(' + ', [...$parts, ...array_values(array_diff($subscription->modules ?? [], $inBundle))]), 0, 255);
    }
}
