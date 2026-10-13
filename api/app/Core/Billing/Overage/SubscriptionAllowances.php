<?php

namespace App\Core\Billing\Overage;

use App\Core\Billing\Models\Subscription;
use App\Core\Limits\Allowances;
use App\Core\Limits\PlanAllowances;
use App\Core\Limits\SelectionAllowances;
use App\Core\Tenancy\TenantContext;

/**
 * LIM-01, SUB-02: the allowances of the plan the tenant subscribes to (its
 * subscription's modules, quantities and bundle), so limits follow the
 * paid plan. Without a subscription, the onboarding selection
 * (SelectionAllowances) still applies. Read in the tenant's own context.
 */
class SubscriptionAllowances implements PlanAllowances
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SelectionAllowances $selection,
    ) {}

    public function for(string $tenantId): Allowances
    {
        $subscription = $this->tenants->run($tenantId, fn () => Subscription::query()->first());

        if ($subscription === null) {
            return $this->selection->for($tenantId);
        }

        return $this->selection->fromSelection([
            'country' => $subscription->country,
            'modules' => array_values($subscription->modules ?? []),
            'quantities' => $subscription->quantities ?? [],
            'bundle' => $subscription->bundle,
        ]);
    }
}
