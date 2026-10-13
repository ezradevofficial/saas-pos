<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\Subscription;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use App\Core\Support\EnvironmentGuard;
use Illuminate\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * Platform console (ADR 016): the Tenants list from the operator's
 * accounts, a tenant's subscription and invoices, trial extension
 * (ONB-04), grace override (SUB-05), bank transfers marked paid (SUB-04)
 * and billing settings; roles checked, every action audited.
 */
class PlatformBillingTest extends TestCase
{
    use BuildsBilling, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
    }

    private function audited(string $action): bool
    {
        return PlatformDatabase::connection()->table('platform_audit_logs')->where('action', $action)->exists();
    }

    public function test_staff_list_tenants_and_see_one_with_its_subscription_and_invoices(): void
    {
        $support = $this->staffBearer($this->staff(PlatformUser::ROLE_SUPPORT));
        $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated();

        $list = $this->getJson('/api/v1/platform/tenants', $support)->assertOk();
        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.tenant_id', $this->owner->tenant_id)
            ->assertJsonPath('data.0.plan', 'starter')->assertJsonPath('data.0.status', 'trial')->assertJsonPath('data.0.country', 'KE');

        $this->getJson("/api/v1/platform/tenants/{$this->owner->tenant_id}", $support)->assertOk()
            ->assertJsonPath('data.subscription.status', 'trial')
            ->assertJsonPath('data.invoices.0.total', ['amount_minor' => 390000, 'currency' => 'KES']);

        // Tenant tokens never reach the console; support may not change anything.
        $this->getJson('/api/v1/platform/tenants', $this->headersFor())->assertUnauthorized();
        $this->postJson("/api/v1/platform/tenants/{$this->owner->tenant_id}/grace", ['grace_days' => 3], $support)->assertForbidden();
        $this->getJson('/api/v1/platform/tenants/00000000-0000-7000-8000-000000000000', $support)->assertNotFound();
    }

    public function test_billing_staff_extend_a_trial_override_grace_and_mark_a_bank_transfer_paid(): void
    {
        $billing = $this->staffBearer($this->staff(PlatformUser::ROLE_BILLING));
        $tenant = $this->owner->tenant_id;

        $this->postJson("/api/v1/platform/tenants/{$tenant}/trial", ['trial_ends_at' => '2026-11-30T00:00:00Z'], $billing)->assertOk()
            ->assertJsonPath('data.account.trial_ends_at', '2026-11-30T00:00:00+00:00');
        $this->assertSame('2026-11-30', $this->subscription()->trial_ends_at->toDateString());
        $this->assertTrue($this->audited('platform.subscription.trial_extend'));

        $this->postJson("/api/v1/platform/tenants/{$tenant}/grace", ['grace_days' => 14], $billing)->assertOk()->assertJsonPath('data.grace_days', 14);
        $this->assertSame(14, $this->subscription()->grace_days);
        $this->assertTrue($this->audited('platform.subscription.grace_override'));

        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->json('data.id');
        $operatorInvoice = $this->inTenant(fn () => BillingInvoice::query()->findOrFail($invoice)->operator_invoice_id);
        $this->postJson("/api/v1/platform/invoices/{$operatorInvoice}/mark-paid", ['reference' => 'EQUITY-0091'], $billing)->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_via', 'bank_transfer');
        $this->assertSame(Subscription::ACTIVE, $this->subscription()->status);
        $this->assertTrue($this->audited('platform.invoice.mark_paid'));
        $this->postJson("/api/v1/platform/invoices/{$operatorInvoice}/mark-paid", ['reference' => 'EQUITY-0091'], $billing)->assertStatus(422);

        // A trial that has become a paid subscription is not extended.
        $this->postJson("/api/v1/platform/tenants/{$tenant}/trial", ['trial_ends_at' => '2026-12-30T00:00:00Z'], $billing)->assertStatus(422);
    }

    public function test_trial_extension_and_grace_override_lock_the_subscription_in_a_transaction(): void
    {
        // A racing "Subscribe now" or billing cycle must not interleave with staff changes.
        $billing = $this->staffBearer($this->staff(PlatformUser::ROLE_BILLING));
        $tenant = $this->owner->tenant_id;
        $locked = [];
        DB::listen(function ($query) use (&$locked) {
            if (preg_match('/from "subscriptions".* for update/i', $query->sql)) {
                $locked[] = $query->connectionName;
            }
        });

        $this->postJson("/api/v1/platform/tenants/{$tenant}/trial", ['trial_ends_at' => '2026-11-30T00:00:00Z'], $billing)->assertOk();
        $this->assertCount(1, $locked, 'the trial extension locks the subscription');
        $this->postJson("/api/v1/platform/tenants/{$tenant}/grace", ['grace_days' => 14], $billing)->assertOk();
        $this->assertCount(2, $locked, 'the grace override locks the subscription');
    }

    public function test_billing_settings_are_platform_data_changed_by_owner_and_billing_staff(): void
    {
        $this->getJson('/api/v1/platform/billing-settings', $this->staffBearer($this->staff(PlatformUser::ROLE_SUPPORT)))->assertOk()
            ->assertJsonPath('data', ['grace_days' => 7, 'pos_window_days' => 3, 'trial_days' => 14]);
        $this->putJson('/api/v1/platform/billing-settings', ['grace_days' => 5], $this->staffBearer($this->staff(PlatformUser::ROLE_SUPPORT)))->assertForbidden();

        $this->putJson('/api/v1/platform/billing-settings', ['grace_days' => 5, 'pos_window_days' => 99], $this->staffBearer($this->staff(PlatformUser::ROLE_OWNER)))->assertStatus(422);
        $this->putJson('/api/v1/platform/billing-settings', ['grace_days' => 5], $this->staffBearer($this->staff(PlatformUser::ROLE_OWNER)))->assertOk()
            ->assertJsonPath('data.grace_days', 5);
        $this->assertTrue($this->audited('platform.billing_settings.update'));
    }

    public function test_no_tenant_can_make_itself_the_operator(): void
    {
        $this->expectException(QueryException::class);
        $this->inTenant(fn () => DB::table('tenants')->where('id', $this->owner->tenant_id)->update(['is_operator' => true]));
    }

    public function test_the_environment_guard_refuses_fake_billing_drivers(): void
    {
        $config = new Repository(['payments' => ['allow_fake' => false, 'drivers' => []], 'billing' => ['drivers' => ['mpesa_ke' => 'fake']]]);
        $this->assertNotNull(EnvironmentGuard::checks($config)['Payment drivers']);

        $config->set('billing.drivers', ['mpesa_ke' => 'mpesa_daraja']);
        $this->assertNull(EnvironmentGuard::checks($config)['Payment drivers']);
    }
}
