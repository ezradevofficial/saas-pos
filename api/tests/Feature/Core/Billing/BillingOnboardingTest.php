<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Operator;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Trial\TrialExpiry;
use App\Core\Onboarding\Trial\TrialStarter;
use App\Core\Onboarding\Trial\TrialStatusProvider;
use App\Core\Billing\Subscriptions\BillingTrial;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesIdentities;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * ONB-04, SUB-02, SUB-03: the trial onboarding starts is the
 * subscription's. Sign-up's module picker opens the trial subscription,
 * the billing cycle turns its end into the first invoice, and a staff
 * extension moves the one trial end (and onboarding's copy of it).
 */
class BillingOnboardingTest extends TestCase
{
    use CreatesIdentities, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    private array $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));
        $this->artisan('platform:operator', ['--country' => ['KE'], '--fake' => true])->assertSuccessful();
        app(Operator::class)->forget();

        $challenge = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Wanjiku Mwangi', 'email' => 'wanjiku@example.com', 'password' => $this->password,
            'country' => 'KE', 'locale' => 'en', 'business_name' => 'Duka la Wanjiku',
        ])->assertCreated()->json('challenge_id');
        $verified = $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challenge, 'code' => $this->lastCode()])->assertOk();
        $this->owner = ['token' => $verified->json('token'), 'tenant' => $verified->json('user.tenant_id')];
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->owner['tenant'], $fn);
    }

    private function pick(array $modules = ['inventory', 'loyalty', 'pos'], ?string $bundle = 'starter'): void
    {
        $this->putJson('/api/v1/onboarding/modules', ['modules' => $modules, 'quantities' => ['outlet' => 1], 'cycle' => 'monthly', 'bundle' => $bundle], $this->bearer($this->owner['token']))->assertOk();
    }

    public function test_billing_owns_the_trial_bindings(): void
    {
        foreach ([TrialStarter::class, TrialStatusProvider::class, TrialExpiry::class] as $contract) {
            $this->assertInstanceOf(BillingTrial::class, app($contract), $contract);
        }
    }

    public function test_sign_up_opens_the_trial_subscription_and_its_end_becomes_the_first_invoice(): void
    {
        $this->pick();

        $subscription = $this->inTenant(fn () => Subscription::query()->sole());
        $this->assertSame([Subscription::TRIAL, 'KES', 'starter', '2026-11-15'], [$subscription->status, $subscription->currency, $subscription->bundle, $subscription->trial_ends_at->toDateString()]);
        $this->assertSame('2026-11-15', $this->inTenant(fn () => OnboardingState::query()->sole()->trial_ends_at->toDateString()));
        $this->assertTrue($this->inTenant(fn () => app(ModuleRegistry::class)->isActive('pos')));
        $this->getJson('/api/v1/onboarding/trial', $this->bearer($this->owner['token']))->assertOk()->assertJsonPath('data.state', 'trial');

        // A second pick during the trial changes the plan at once; the end date stays.
        $this->pick(['inventory', 'loyalty', 'payroll', 'pos']);
        $subscription = $this->inTenant(fn () => Subscription::query()->sole());
        $this->assertSame([['inventory', 'loyalty', 'payroll', 'pos'], '2026-11-15'], [$subscription->modules, $subscription->trial_ends_at->toDateString()]);

        // The trial ends: the billing cycle issues the first invoice; onboarding's expiry leaves the modules on.
        $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00', 'UTC'));
        $this->artisan('billing:cycle')->assertSuccessful();
        $this->artisan('onboarding:expire-trials')->assertSuccessful();

        $invoice = $this->inTenant(fn () => BillingInvoice::query()->sole());
        $this->assertSame(['first', '2026-11-15', 590000], [$invoice->kind, $invoice->period_start->toDateString(), $invoice->total_minor]);
        $this->assertTrue($this->inTenant(fn () => app(ModuleRegistry::class)->isActive('pos')));
    }

    public function test_a_staff_extension_moves_the_one_trial_end(): void
    {
        $this->pick();
        $staff = $this->staffBearer($this->staff(PlatformUser::ROLE_BILLING));

        $this->postJson("/api/v1/platform/tenants/{$this->owner['tenant']}/trial", ['trial_ends_at' => '2026-11-30T12:00:00Z'], $staff)->assertOk();

        $this->assertSame('2026-11-30', $this->inTenant(fn () => Subscription::query()->sole()->trial_ends_at->toDateString()));
        $this->assertSame('2026-11-30', $this->inTenant(fn () => OnboardingState::query()->sole()->trial_ends_at->toDateString()));
        $this->getJson('/api/v1/onboarding/trial', $this->bearer($this->owner['token']))->assertOk()->assertJsonPath('data.days_left', 30);

        // The old end passes without an invoice; the new one issues it.
        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00', 'UTC'));
        $this->artisan('billing:cycle')->assertSuccessful();
        $this->assertSame(0, $this->inTenant(fn () => BillingInvoice::query()->count()));
        $this->travelTo(CarbonImmutable::parse('2026-11-30 13:00:00', 'UTC'));
        $this->artisan('billing:cycle')->assertSuccessful();
        $this->assertSame(1, $this->inTenant(fn () => BillingInvoice::query()->count()));
    }
}
