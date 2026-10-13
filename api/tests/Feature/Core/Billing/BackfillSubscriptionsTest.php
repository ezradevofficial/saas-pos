<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Billing\Catalogue\Models\CatalogueModule;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Operator;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\TestCase;

/**
 * SUB-02, ONB-04: `billing:backfill-subscriptions` gives tenants created
 * before billing a trial subscription, from the onboarding selection or
 * else the active modules; idempotent; tenant by tenant in its own
 * context, never the owner connection (ADR 002); the operator is skipped.
 */
class BackfillSubscriptionsTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase, ResetsPlatformTables, WithoutOwnerConnection;

    public function test_tenants_without_a_subscription_get_a_trial_once(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));
        $this->artisan('platform:operator', ['--country' => ['KE', 'CD'], '--fake' => true])->assertSuccessful();
        app(Operator::class)->forget();
        $this->setUpOrganisation();
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('pos'));
        // A second tenant that picked its modules at sign-up.
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => OnboardingState::current()->fill([
            'selection' => ['country' => 'KE', 'modules' => ['inventory', 'loyalty', 'pos'], 'quantities' => ['outlet' => 1], 'cycle' => 'monthly', 'bundle' => 'starter'],
        ])->save());
        $this->withoutOwnerConnection();

        $this->artisan('billing:backfill-subscriptions')->assertSuccessful();

        $sold = CatalogueModule::on(PlatformDatabase::READ)->pluck('key')->all();
        $mine = $this->inTenant(fn () => Subscription::query()->sole());
        $active = $this->inTenant(fn () => array_values(array_intersect(app(ModuleRegistry::class)->active(), $sold)));
        sort($active);
        $this->assertSame([Subscription::TRIAL, 'KES', null, '2026-11-15'], [$mine->status, $mine->currency, $mine->bundle, $mine->trial_ends_at->toDateString()]);
        $this->assertContains('pos', $mine->modules);
        $this->assertSame($active, $mine->modules);

        $theirs = $this->asTenant($other['user']->tenant_id, fn () => Subscription::query()->sole());
        $this->assertSame(['starter', ['inventory', 'loyalty', 'pos']], [$theirs->bundle, $theirs->modules]);
        $this->assertSame('2026-11-15', $this->asTenant($other['user']->tenant_id, fn () => OnboardingState::query()->sole()->trial_ends_at->toDateString()));

        // The operator sells; it is never a customer.
        $this->assertSame(0, app(Operator::class)->run(fn () => Subscription::query()->count()));

        // Idempotent: a second run changes nothing.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00:00', 'UTC'));
        $this->artisan('billing:backfill-subscriptions')->assertSuccessful();
        $this->assertSame([1, '2026-11-15'], $this->inTenant(fn () => [Subscription::query()->count(), Subscription::query()->sole()->trial_ends_at->toDateString()]));
        $this->assertSame(1, $this->asTenant($other['user']->tenant_id, fn () => Subscription::query()->count()));
    }
}
