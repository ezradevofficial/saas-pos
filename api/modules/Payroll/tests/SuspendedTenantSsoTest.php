<?php

namespace Modules\Payroll\Tests;

use App\Core\Branding\Domains\DnsResolver;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\Payroll\Tests\Concerns\BuildsPayroll;
use Tests\Concerns\BuildsSso;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Branding\FakeDnsResolver;
use Tests\Support\Sso\FakeOidcIdp;
use Tests\TestCase;

/**
 * Phase 8 review M1 (AUTH-11, ADM-01c, PAY-28, LIM-04): a suspended tenant
 * keeps payroll, and with SSO enforced its staff still sign in, by SSO;
 * once in, EnforceSubscription still refuses what suspension stops.
 */
class SuspendedTenantSsoTest extends TestCase
{
    use BuildsPayroll, BuildsSso, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->setUpPayroll();
        // BuildsSso's helpers, on the organisation BuildsPayroll made.
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->idp = (new FakeOidcIdp)->fake();
        config(['app.frontend_url' => 'https://app.example.test', 'sso.callback_base' => 'https://api.example.test']);
    }

    public function test_a_payroll_officer_of_a_suspended_tenant_with_enforced_sso_signs_in_and_runs_payroll(): void
    {
        $this->payrollEmployee();
        $this->inTenant(fn () => $this->owner->forceFill(['email' => 'owner@acme.co.ke'])->save());
        $this->inTenant(fn () => $this->officer->forceFill(['email' => 'officer@acme.co.ke', 'email_verified_at' => now()])->save());
        $this->verifyDomain('acme.co.ke');
        $this->entraConnection();
        $this->putJson('/api/v1/sso/settings', ['enforce' => true], $this->headersFor())->assertOk();

        $this->inTenant(fn () => Tenant::query()->whereKey($this->owner->tenant_id)->update(['status' => 'suspended']));

        // Password sign-in stays refused while SSO is enforced; SSO finds the suspended tenant.
        $this->signIn('officer@acme.co.ke')->assertForbidden()->assertJsonPath('code', 'sso_required');
        $done = $this->entraSignIn('officer@acme.co.ke', ['amr' => ['pwd', 'mfa']]);
        $token = $this->completeSso($this->ticketFrom($done['location']), $done['binding'])->assertOk()->json('token');
        $this->assertIsString($token);
        $headers = $this->bearer($token);

        $run = $this->postJson('/api/v1/payroll/runs', ['pay_group_id' => $this->monthly->id, 'period_date' => '2026-10-15'], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate", [], $headers)->assertOk()->assertJsonPath('data.status', 'calculated');

        // Not an exempt write: refused as suspended.
        $this->postJson('/api/v1/payroll/pay-groups', ['company_id' => $this->acme->id, 'code' => 'WEEKLY', 'name' => 'Weekly', 'frequency' => 'weekly'], $headers)
            ->assertForbidden()->assertJsonPath('code', 'tenant_suspended');
    }
}
