<?php

namespace Tests\Feature\Core\Platform;

use App\Core\Audit\AuditEntry;
use App\Core\Billing\Http\EnforceSubscription;
use App\Core\Billing\Models\OperatorAccountEvent;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Accounts;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use App\Core\Platform\Tenants\PlatformNotices;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * ADM-01 (docs/modules/platform-admin.md): tenant search, a tenant's usage
 * and health, and suspend, reactivate and trial extension with a reason,
 * audited in both chains and told to the Owners.
 */
class PlatformTenantManagementTest extends TestCase
{
    use BuildsBilling, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    private array $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
        $this->other = $this->otherTenant();
        $this->asTenant($this->other['user']->tenant_id, fn () => app(Subscriptions::class)->startTrial('CD', ['modules' => ['crm'], 'quantities' => ['company' => 1], 'cycle' => 'monthly']));
    }

    private function search(array $query, ?string $role = PlatformUser::ROLE_SALES): array
    {
        return $this->getJson('/api/v1/platform/tenants?'.http_build_query($query), $this->staffBearer($this->staff($role)))->assertOk()->json('data');
    }

    public function test_staff_search_tenants_by_name_slug_owner_email_country_plan_status_and_module(): void
    {
        $mine = $this->owner->tenant_id;
        $theirs = $this->other['user']->tenant_id;
        $this->inTenant(fn () => Tenant::query()->whereKey($mine)->update(['slug' => 'duka-moja']));
        // The account follows the subscription (and picks up the slug) on its next sync.
        $this->inTenant(fn () => app(Accounts::class)->sync(Subscription::query()->firstOrFail()));

        $this->assertCount(2, $this->search([]));
        $this->assertSame([$mine], array_column($this->search(['search' => 'duka-mo']), 'tenant_id'));
        $this->assertSame([$theirs], array_column($this->search(['country' => 'CD']), 'tenant_id'));
        $this->assertSame([$mine], array_column($this->search(['plan' => 'starter']), 'tenant_id'));
        $this->assertSame([$mine], array_column($this->search(['module' => 'loyalty']), 'tenant_id'));
        $this->assertSame([$theirs], array_column($this->search(['module' => 'crm']), 'tenant_id'));
        $this->assertSame([], $this->search(['status' => 'active']));
        $this->assertCount(2, $this->search(['status' => 'trial']));

        // Owner email: the sign-in lookup, then the Owner role checked in that tenant.
        $this->assertSame([$theirs], array_column($this->search(['owner_email' => strtoupper($this->other['user']->email)]), 'tenant_id'));
        $this->assertSame([], $this->search(['owner_email' => 'nobody@example.com']));
        $cashier = $this->userWith('cashier', Scope::tenant());
        $this->assertSame([], $this->search(['owner_email' => $cashier->email]), 'a user who is not an Owner finds nothing');

        $this->getJson('/api/v1/platform/tenants?status=unknown', $this->staffBearer($this->staff()))->assertStatus(422);
    }

    public function test_a_tenant_shows_plan_modules_usage_health_and_billing_as_counts_only(): void
    {
        $this->inTenant(function () {
            $device = Device::query()->create(['location_id' => $this->locationA->id, 'name' => 'Till 1', 'code' => 'T1', 'status' => Device::STATUS_ACTIVE]);
            $device->forceFill(['last_seen_at' => now()->subMinutes(30)])->save();
        });
        $this->artisan('usage:meter')->assertSuccessful();

        $data = $this->getJson("/api/v1/platform/tenants/{$this->owner->tenant_id}", $this->staffBearer($this->staff(PlatformUser::ROLE_ENGINEERING)))->assertOk()->json('data');

        $this->assertSame('starter', $data['account']['bundle']);
        $this->assertSame(['inventory', 'loyalty', 'pos'], $data['account']['modules']);
        $this->assertContains('pos', $data['modules']);
        $this->assertSame('active', $data['status']);
        $this->assertSame('2026-11-01', $data['usage']['day']);
        $this->assertContains('device', array_column($data['usage']['units'], 'unit'));
        $this->assertSame(1, $data['health']['devices']);
        $this->assertSame(1800, $data['health']['sync_lag_seconds']);
        $this->assertSame(0, $data['health']['failed_jobs']);
        $this->assertNotNull($data['health']['last_activity_at']);
        $this->assertArrayHasKey('posting_exceptions', $data['health']);
        $this->assertSame('trial', $data['subscription']['status']);
        // Ids and counts only: no business records of the tenant.
        $this->assertArrayNotHasKey('users', $data);
        $this->assertArrayNotHasKey('items', $data);
    }

    public function test_suspend_refuses_writes_audits_both_chains_and_tells_the_owners_then_reactivate_restores(): void
    {
        $tenant = $this->owner->tenant_id;
        $support = $this->staffBearer($staff = $this->staff(PlatformUser::ROLE_SUPPORT));

        $this->postJson("/api/v1/platform/tenants/{$tenant}/suspend", [], $support)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/platform/tenants/{$tenant}/suspend", ['reason' => 'Chargeback fraud under review'], $support)->assertOk()
            ->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.subscription.status', 'suspended');
        $this->postJson("/api/v1/platform/tenants/{$tenant}/suspend", ['reason' => 'Chargeback fraud under review'], $support)->assertStatus(422);

        // Writes refused, reads and billing still allowed.
        $this->postJson('/api/v1/companies', ['name' => 'New co', 'country' => 'KE', 'base_currency' => 'KES', 'timezone' => 'Africa/Nairobi'], $this->headersFor())
            ->assertForbidden()->assertJsonPath('code', 'tenant_suspended');
        $this->getJson('/api/v1/companies', $this->headersFor())->assertOk();
        $this->getJson('/api/v1/subscription', $this->headersFor())->assertOk();
        $this->assertSame('suspended', $this->inTenant(fn () => Tenant::query()->value('status')));

        // The tenant's chain names the staff member; the platform chain names the tenant; the Owners are told.
        $entry = $this->inTenant(fn () => AuditEntry::query()->where('action', 'platform.tenant.suspend')->firstOrFail());
        $this->assertNull($entry->user_id);
        $this->assertSame($staff->id, $entry->after['metadata']['platform_user_id']);
        $this->assertSame('Chargeback fraud under review', $entry->after['reason']);
        $platform = PlatformDatabase::connection()->table('platform_audit_logs')->where('action', 'platform.tenant.suspend')->first();
        $this->assertSame($tenant, json_decode($platform->after, true)['tenant_id']);
        $this->assertSame($staff->id, $platform->platform_user_id);
        $this->assertContains(PlatformNotices::SUSPENDED, $this->ownerNotices());
        $this->assertTrue($this->inOperator(fn () => OperatorAccountEvent::query()->where('customer_tenant_id', $tenant)->where('kind', OperatorAccountEvent::SUSPENDED)->exists()));

        $this->postJson("/api/v1/platform/tenants/{$tenant}/reactivate", ['reason' => 'Bank confirmed the payments'], $support)->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.subscription.status', 'trial');
        $this->assertNull($this->subscription()->status_before_suspension);
        $this->assertContains(PlatformNotices::REACTIVATED, $this->ownerNotices());
        $this->postJson("/api/v1/platform/tenants/{$tenant}/reactivate", ['reason' => 'Bank confirmed the payments'], $support)->assertStatus(422);
    }

    public function test_extend_trial_needs_a_reason_and_tells_the_owners(): void
    {
        $tenant = $this->owner->tenant_id;
        $finance = $this->staffBearer($this->staff(PlatformUser::ROLE_FINANCE));

        $this->postJson("/api/v1/platform/tenants/{$tenant}/trial", ['trial_ends_at' => '2026-11-30T00:00:00Z'], $finance)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/platform/tenants/{$tenant}/trial", ['trial_ends_at' => '2026-11-30T00:00:00Z', 'reason' => 'Waiting for the payroll pack'], $finance)->assertOk();
        $this->assertSame('2026-11-30', $this->subscription()->trial_ends_at->toDateString());
        $this->assertContains(PlatformNotices::TRIAL_EXTENDED, $this->ownerNotices());
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'platform.subscription.trial_extend')->exists()));
    }

    /** ADM-01c, owner decision 2026-10-17: suspension keeps billing, the account, the export, approvals and payroll (PAY-28, LIM-04). */
    public function test_a_suspended_tenant_keeps_approvals_and_payroll_but_not_tills_or_other_writes(): void
    {
        $expect = [
            'POST approvals/bulk-approve' => true,
            'POST approvals/{approval}/approve' => true,
            'POST pos/voids/{pos_void}/approve' => true,
            'POST payroll/runs' => true,
            'POST payroll/runs/{payroll_run}/calculate' => true,
            'POST payroll/runs/{payroll_run}/post' => true,
            'POST payroll/runs/{payroll_run}/corrections' => true,
            'POST payroll/runs/{payroll_run}/cancel' => true,
            'POST payroll/returns' => true,
            'POST subscription/subscribe' => true,
            'POST tenant-exports' => true,
            'POST auth/sign-out' => true,
            'POST hr/timesheets/approve' => false,
            'POST hr/me/clock' => false,
            'POST pos/sales' => false,
            'POST pos/shifts' => false,
            'POST parties' => false,
        ];

        foreach ($expect as $key => $allowed) {
            [$method, $uri] = explode(' ', $key, 2);
            $this->assertSame($allowed, EnforceSubscription::allowedWhileSuspended(EnforceSubscription::classify($method, $uri), $uri), $key);
        }

        $this->postJson("/api/v1/platform/tenants/{$this->owner->tenant_id}/suspend", ['reason' => 'Chargeback fraud under review'], $this->staffBearer($this->staff(PlatformUser::ROLE_SUPPORT)))->assertOk();
        $this->assertNotSame('tenant_suspended', $this->postJson('/api/v1/approvals/bulk-approve', ['ids' => []], $this->headersFor())->json('code'));
        $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'roles' => ['customer'], 'name' => 'New co'], $this->headersFor())->assertForbidden()->assertJsonPath('code', 'tenant_suspended');
    }

    public function test_only_staff_with_tenant_management_act_on_tenants(): void
    {
        $tenant = $this->owner->tenant_id;

        foreach ([PlatformUser::ROLE_SALES, PlatformUser::ROLE_COMPLIANCE, PlatformUser::ROLE_ENGINEERING] as $role) {
            $bearer = $this->staffBearer($this->staff($role));
            $this->postJson("/api/v1/platform/tenants/{$tenant}/suspend", ['reason' => 'Chargeback fraud under review'], $bearer)->assertForbidden();
            $this->postJson("/api/v1/platform/tenants/{$tenant}/trial", ['trial_ends_at' => '2026-11-30T00:00:00Z', 'reason' => 'Waiting for the payroll pack'], $bearer)->assertForbidden();
        }

        $this->postJson("/api/v1/platform/tenants/{$tenant}/suspend", ['reason' => 'Chargeback fraud under review'], $this->headersFor())->assertUnauthorized();
        $this->assertSame('active', $this->inTenant(fn () => Tenant::query()->value('status')));
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
