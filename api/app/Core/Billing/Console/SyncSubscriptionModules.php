<?php

namespace App\Core\Billing\Console;

use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SUB-02, SUB-07, RBAC-08: a deploy step. A module installed after a
 * tenant subscribed is switched on when the tenant's plan (or its bundle)
 * covers it; nothing is switched off here (plan changes and the billing
 * cycle do that). Suspended and cancelled subscriptions are left alone.
 *
 * Tenant ids come from DueTenants (a security-definer function returning
 * ids only, ADR 002); each tenant is handled in its own context, never on
 * the owner connection. Idempotent: a second run switches nothing on.
 */
class SyncSubscriptionModules extends Command
{
    protected $signature = 'billing:sync-modules';

    protected $description = 'Switch on the installed modules each subscription covers (deploy step)';

    public function handle(DueTenants $due, TenantContext $tenants, Subscriptions $subscriptions): int
    {
        $operator = $due->operator();
        $switched = 0;

        foreach ($due->active() as $tenantId) {
            if ($tenantId === $operator) {
                continue;
            }

            $on = $tenants->run($tenantId, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($subscriptions) {
                $subscription = Subscription::query()->lockForUpdate()->first();

                if ($subscription === null || in_array($subscription->status, [Subscription::SUSPENDED, Subscription::CANCELLED], true)) {
                    return [];
                }

                return $subscriptions->syncModules($subscription, switchOff: false);
            }));

            if ($on !== []) {
                $switched += count($on);
                $this->line("{$tenantId}: ".implode(', ', $on));
            }
        }

        $this->components->info("{$switched} modules switched on.");

        return self::SUCCESS;
    }
}
