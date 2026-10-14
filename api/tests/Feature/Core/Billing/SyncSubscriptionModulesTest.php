<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Billing\Models\Subscription;
use App\Core\Rbac\ModuleRegistry;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\TestCase;

/**
 * SUB-02, SUB-07, RBAC-08: modules installed after a tenant subscribed
 * are switched on by the deploy's `billing:sync-modules` when the plan
 * covers them; nothing is switched off; idempotent; suspended tenants are
 * left alone; tenant by tenant, never the owner connection (ADR 002).
 */
class SyncSubscriptionModulesTest extends TestCase
{
    use BuildsBilling, RefreshTenantDatabase, ResetsPlatformTables, WithoutOwnerConnection;

    public function test_installed_modules_the_plan_covers_are_switched_on_once(): void
    {
        $this->setUpBilling();
        // The plan names Accounting (Essentials) and Loyalty, but those modules were
        // installed after the subscription was made: they are still off.
        $this->inTenant(function () {
            Subscription::query()->firstOrFail()->forceFill(['modules' => ['accounting_essentials', 'inventory', 'loyalty', 'pos']])->saveQuietly();
            app(ModuleRegistry::class)->deactivate('loyalty');
        });
        $this->withoutOwnerConnection();

        $this->artisan('billing:sync-modules')->assertSuccessful();

        $active = $this->inTenant(fn () => app(ModuleRegistry::class)->active());
        foreach (['accounting', 'inventory', 'loyalty', 'pos'] as $module) {
            $this->assertContains($module, $active, $module);
        }

        // Idempotent.
        $this->artisan('billing:sync-modules')->expectsOutputToContain('0 modules switched on.')->assertSuccessful();
    }

    public function test_a_suspended_subscription_is_left_alone(): void
    {
        $this->setUpBilling();
        $this->inTenant(function () {
            Subscription::query()->firstOrFail()->forceFill(['status' => Subscription::SUSPENDED])->saveQuietly();
            app(ModuleRegistry::class)->deactivate('loyalty');
        });

        $this->artisan('billing:sync-modules')->assertSuccessful();

        $this->assertNotContains('loyalty', $this->inTenant(fn () => app(ModuleRegistry::class)->active()));
    }
}
