<?php

namespace Tests\Feature\Core\Platform;

use App\Core\Audit\AuditContext;
use App\Core\Audit\AuditEntry;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Identity\Models\User;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\PlatformDatabase;
use App\Core\Platform\SupportAccess\Models\SupportAccessGrant;
use App\Core\Platform\SupportAccess\SupportAccess;
use App\Core\Platform\SupportAccess\SupportNotices;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * RBAC-13, ADM-03 (docs/modules/platform-admin.md): staff see a tenant only
 * after the tenant grants time-limited access; read-only sessions cannot
 * write; sensitive data stays hidden unless granted; every request is in
 * the tenant's audit and the platform chain; revocation and expiry end the
 * session at once; a grant never opens another tenant.
 */
class SupportAccessTest extends TestCase
{
    use BuildsBilling, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    private PlatformUser $agent;

    private array $agentBearer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
        $this->agent = $this->staff(PlatformUser::ROLE_SUPPORT);
        $this->agentBearer = $this->staffBearer($this->agent);
    }

    private function ask(string $mode = 'read', int $hours = 4, ?string $tenant = null, ?array $bearer = null): string
    {
        $tenant ??= $this->owner->tenant_id;

        return $this->postJson("/api/v1/platform/tenants/{$tenant}/support-requests", ['reason' => 'Customer reports the stock count import fails', 'mode' => $mode, 'hours' => $hours], $bearer ?? $this->agentBearer)
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    }

    private function approve(string $grant, bool $sensitive = false, ?string $mode = null): void
    {
        $this->postJson("/api/v1/support-access/{$grant}/approve", array_filter(['include_sensitive' => $sensitive, 'mode' => $mode], fn ($v) => $v !== null), $this->headersFor())
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.include_sensitive', $sensitive);
    }

    /** @return array{0: array<string, string>, 1: string} the session's bearer headers and token */
    private function enter(string $grant): array
    {
        $code = $this->postJson("/api/v1/platform/support/requests/{$grant}/session", [], $this->agentBearer)->assertOk()->json('data.entry_code');
        $token = $this->postJson('/api/v1/auth/support-session', ['code' => $code])->assertOk()->json('data.token');

        return [['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'], $token];
    }

    public function test_nothing_opens_without_the_tenants_consent(): void
    {
        $grant = $this->ask();

        $this->postJson("/api/v1/platform/support/requests/{$grant}/session", [], $this->agentBearer)->assertForbidden()->assertJsonPath('code', 'support_access_not_granted');
        // One open request per staff member and tenant.
        $this->postJson("/api/v1/platform/tenants/{$this->owner->tenant_id}/support-requests", ['reason' => 'Customer reports the stock count import fails', 'mode' => 'read', 'hours' => 4], $this->agentBearer)->assertStatus(409);

        // The tenant's Owner is told in the app (and by email), and sees the request.
        $this->assertContains(SupportNotices::REQUESTED, $this->ownerNotices());
        $this->getJson('/api/v1/support-access', $this->headersFor())->assertOk()->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.staff_name', $this->agent->name);

        // Denied: still nothing.
        $this->postJson("/api/v1/support-access/{$grant}/deny", ['note' => 'Not now'], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'denied');
        $this->postJson("/api/v1/platform/support/requests/{$grant}/session", [], $this->agentBearer)->assertForbidden();
        $this->getJson('/api/v1/platform/support/requests', $this->agentBearer)->assertOk()->assertJsonPath('data.0.status', 'denied');

        // Only those who manage support access decide; a cashier cannot.
        $other = $this->ask();
        $cashier = $this->userWith('cashier', Scope::tenant());
        $this->postJson("/api/v1/support-access/{$other}/approve", ['include_sensitive' => false], $this->headersFor($cashier))->assertForbidden();
        $this->postJson("/api/v1/support-access/{$other}/approve", [], $this->headersFor())->assertStatus(422)->assertJsonValidationErrors('include_sensitive');
    }

    public function test_a_read_only_session_reads_cannot_write_and_never_sees_sensitive_data(): void
    {
        $grant = $this->ask('write');
        // The tenant narrows the request to read-only and leaves sensitive data out.
        $this->approve($grant, sensitive: false, mode: 'read');
        [$session] = $this->enter($grant);

        $this->getJson('/api/v1/me', $session)->assertOk()
            ->assertJsonPath('data.support_session.mode', 'read')->assertJsonPath('data.support_session.staff_name', $this->agent->name);
        $permissions = array_column($this->getJson('/api/v1/me/permissions', $session)->assertOk()->json('permissions'), 'name');
        $this->assertContains('core.party.view', $permissions);
        $this->assertNotContains('core.party.create', $permissions);
        $this->assertNotContains('core.payment_method.view', $permissions);

        $this->getJson('/api/v1/parties', $session)->assertOk();
        $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'roles' => ['customer'], 'name' => 'Support Co'], $session)->assertForbidden()->assertJsonPath('code', 'support_read_only');
        // Payment data is sensitive: refused unless the tenant included it.
        $this->getJson("/api/v1/companies/{$this->acme->id}/payment-methods", $session)->assertForbidden();
        // The principal's own account and support access itself are out of reach.
        $this->patchJson('/api/v1/me', ['name' => 'X'], $session)->assertForbidden()->assertJsonPath('code', 'support_route_refused');
        $this->getJson('/api/v1/support-access', $session)->assertForbidden();

        // The principal is not a user of the tenant's lists, and never signs in by password.
        $this->assertNotContains($this->agent->name.' (support)', array_column($this->getJson('/api/v1/users', $this->headersFor())->json('data'), 'name'));
        $principal = $this->inTenant(fn () => User::query()->whereNotNull('support_grant_id')->sole());
        $this->postJson('/api/v1/auth/sign-in', ['login' => $principal->email, 'password' => 'anything-at-all-1'])->assertStatus(422);
    }

    public function test_a_write_session_with_sensitive_data_granted_writes_but_never_manages_people_or_roles(): void
    {
        $grant = $this->ask('write', 2);
        $this->approve($grant, sensitive: true);
        [$session] = $this->enter($grant);

        $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'roles' => ['customer'], 'name' => 'Support Co'], $session)->assertCreated();
        $this->getJson("/api/v1/companies/{$this->acme->id}/payment-methods", $session)->assertOk();
        $this->postJson('/api/v1/invitations', ['name' => 'Mole', 'email' => 'mole@example.com', 'role_id' => $this->roles->get('owner')->id, 'scope_type' => 'tenant'], $session)->assertForbidden();
        $this->postJson("/api/v1/support-access/{$grant}/revoke", [], $session)->assertForbidden();
    }

    public function test_every_request_is_in_the_tenants_audit_and_the_platform_chain(): void
    {
        $grant = $this->ask();
        $this->approve($grant);
        [$session] = $this->enter($grant);

        $this->getJson('/api/v1/parties', $session)->assertOk();
        $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'roles' => ['customer'], 'name' => 'Support Co'], $session)->assertForbidden();

        // Visible to the tenant: the session's activity, from its own audit.
        $activity = $this->getJson("/api/v1/support-access/{$grant}/activity", $this->headersFor())->assertOk()->json('data');
        $requests = array_values(array_filter($activity, fn ($e) => $e['action'] === 'core.support_session.request'));
        $this->assertSame([['POST', 'parties', 403], ['GET', 'parties', 200]], array_map(fn ($e) => [$e['method'], $e['route'], $e['status']], $requests));
        $this->assertTrue($requests[0]['by_principal']);
        $this->assertContains('core.support_session.start', array_column($activity, 'action'));
        $this->assertSame(2, $this->getJson('/api/v1/support-access', $this->headersFor())->json('data.0.requests'));

        $platform = PlatformDatabase::connection()->table('platform_audit_logs')->where('action', 'platform.support.request_made')->orderBy('seq')->get();
        $this->assertCount(2, $platform);
        $this->assertSame($this->agent->id, $platform[0]->platform_user_id);
        $this->assertSame($this->owner->tenant_id, json_decode($platform[0]->after, true)['tenant_id']);
        $this->assertNull(app(PlatformAuditor::class)->verify());
    }

    public function test_revoking_ends_the_session_at_once(): void
    {
        $grant = $this->ask();
        $this->approve($grant);
        [$session] = $this->enter($grant);
        $this->getJson('/api/v1/parties', $session)->assertOk();

        $this->getJson('/api/v1/support-access/active', $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $grant);
        $this->postJson("/api/v1/support-access/{$grant}/revoke", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/parties', $session)->assertUnauthorized();
        $this->getJson('/api/v1/support-access/active', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(User::STATUS_DEACTIVATED, $this->inTenant(fn () => User::query()->whereNotNull('support_grant_id')->sole()->status));
        $this->assertContains(SupportNotices::ENDED, $this->ownerNotices());
        $this->postJson("/api/v1/platform/support/requests/{$grant}/session", [], $this->agentBearer)->assertForbidden();
    }

    public function test_access_ends_at_expiry_and_the_sweep_closes_it(): void
    {
        $grant = $this->ask(hours: 1);
        $this->approve($grant);
        [$session, $token] = $this->enter($grant);
        $this->getJson('/api/v1/parties', $session)->assertOk();

        // The owner signs in again (their own session idles out after an hour).
        $this->travelToDay(CarbonImmutable::now()->addMinutes(61)->toDateTimeString());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/parties', $session)->assertUnauthorized();
        $this->getJson('/api/v1/support-access', $this->headersFor())->assertOk()->assertJsonPath('data.0.status', 'expired');

        $this->artisan('support:expire')->assertSuccessful();
        $this->assertSame(SupportAccessGrant::EXPIRED, $this->inTenant(fn () => SupportAccessGrant::query()->findOrFail($grant)->status));
        $this->assertSame(0, PersonalAccessToken::query()->where('id', explode('|', $token)[0])->count());
        $this->assertSame('expired', $this->getJson('/api/v1/platform/support/requests', $this->agentBearer)->json('data.0.status'));
    }

    public function test_the_entry_code_works_once_for_a_minute_and_staff_end_their_session(): void
    {
        $grant = $this->ask();
        $this->approve($grant);
        $code = $this->postJson("/api/v1/platform/support/requests/{$grant}/session", [], $this->agentBearer)->assertOk()->json('data.entry_code');
        $this->assertStringStartsWith($this->owner->tenant_id.'.', $code);

        // Another staff member cannot open someone else's session.
        $this->postJson("/api/v1/platform/support/requests/{$grant}/session", [], $this->staffBearer($this->staff(PlatformUser::ROLE_SUPPORT)))->assertNotFound();

        // The code names its tenant: another tenant's id finds nothing.
        $other = $this->otherTenant();
        $this->postJson('/api/v1/auth/support-session', ['code' => $other['user']->tenant_id.'.'.substr($code, 37)])->assertNotFound();
        $token = $this->postJson('/api/v1/auth/support-session', ['code' => $code])->assertOk()->json('data.token');
        $this->postJson('/api/v1/auth/support-session', ['code' => $code])->assertNotFound();

        $stale = $this->postJson("/api/v1/platform/support/requests/{$grant}/session", [], $this->agentBearer)->json('data.entry_code');
        $this->travelTo(CarbonImmutable::now()->addSeconds(61));
        $this->postJson('/api/v1/auth/support-session', ['code' => $stale])->assertNotFound();

        $this->postJson("/api/v1/platform/support/requests/{$grant}/end", [], $this->agentBearer)->assertOk()->assertJsonPath('data.status', 'ended');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/parties', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_a_grant_never_crosses_tenants(): void
    {
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(Subscriptions::class)->startTrial('KE', self::STARTER));
        $theirs = $this->ask(tenant: $other['user']->tenant_id);
        $grant = $this->ask();

        // Tenant B's Owner cannot see or decide A's request, nor A's Owner B's.
        $this->postJson("/api/v1/support-access/{$grant}/approve", ['include_sensitive' => false], $this->headersFor($other['user']))->assertNotFound();
        $this->postJson("/api/v1/support-access/{$theirs}/approve", ['include_sensitive' => false], $this->headersFor())->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/v1/support-access', $this->headersFor($other['user']))->json('data'));

        $this->approve($grant);
        [$session] = $this->enter($grant);
        // The session's token is bound to tenant A: B's records are not found, B's lists show nothing of B.
        $this->getJson("/api/v1/companies/{$other['company']->id}", $session)->assertNotFound();
        $this->assertNotContains($other['company']->id, array_column($this->getJson('/api/v1/companies', $session)->assertOk()->json('data'), 'id'));
        $this->assertSame(SupportAccessGrant::PENDING, $this->asTenant($other['user']->tenant_id, fn () => SupportAccessGrant::query()->findOrFail($theirs)->status));
    }

    public function test_only_support_capable_staff_ask_and_tenant_tokens_never_reach_the_console(): void
    {
        foreach ([PlatformUser::ROLE_SALES, PlatformUser::ROLE_FINANCE, PlatformUser::ROLE_COMPLIANCE] as $role) {
            $this->postJson("/api/v1/platform/tenants/{$this->owner->tenant_id}/support-requests", ['reason' => 'Customer reports the stock count import fails', 'mode' => 'read', 'hours' => 4], $this->staffBearer($this->staff($role)))->assertForbidden();
        }

        $this->postJson("/api/v1/platform/tenants/{$this->owner->tenant_id}/support-requests", ['reason' => 'Too long', 'mode' => 'read', 'hours' => 73], $this->agentBearer)->assertStatus(422)->assertJsonValidationErrors(['hours', 'reason']);
        $this->getJson('/api/v1/platform/support/requests', $this->headersFor())->assertUnauthorized();
        $this->assertTrue($this->inTenant(fn () => ! AuditEntry::query()->where('action', 'core.support_access.create')->exists()));
    }

    /** Review H1: every NEVER entry names real catalogue permissions (a typo would silently match nothing). */
    public function test_every_never_entry_matches_a_catalogue_permission(): void
    {
        $names = app(PermissionRegistry::class)->all()->keys()->all();

        foreach (SupportAccess::NEVER as $pattern) {
            $this->assertNotEmpty(array_filter($names, fn (string $name) => Str::is($pattern, $name)), "NEVER entry {$pattern} matches no permission");
        }
    }

    /** Review H1: nothing that makes a credential, an outbound link, a schedule or an export reaches a session. */
    public function test_a_write_session_never_makes_credentials_schedules_or_exports(): void
    {
        $grant = $this->ask('write', 2);
        $this->approve($grant, sensitive: true);
        [$session] = $this->enter($grant);
        $device = $this->inTenant(fn () => Device::query()->create(['location_id' => $this->locationA->id, 'name' => 'Till 9', 'code' => 'T9', 'status' => Device::STATUS_PENDING]));

        $permissions = array_column($this->getJson('/api/v1/me/permissions', $session)->assertOk()->json('permissions'), 'name');
        foreach (['core.device.pair', 'core.device.create', 'core.automation.edit', 'core.notification_template.edit', 'pos.sale.share'] as $never) {
            $this->assertNotContains($never, $permissions, $never);
        }

        $this->postJson("/api/v1/devices/{$device->id}/pairing-code", [], $session)->assertForbidden();
        $this->postJson('/api/v1/automation-rules', ['name' => 'Exfiltrate'], $session)->assertForbidden();
        $this->postJson("/api/v1/companies/{$this->acme->id}/accounting/recurring-journals", [], $session)->assertForbidden()->assertJsonPath('code', 'support_route_refused');
        $this->getJson('/api/v1/parties?format=csv', $session)->assertForbidden()->assertJsonPath('code', 'support_route_refused');
        $this->getJson('/api/v1/parties', $session)->assertOk();
    }

    /** Review H1: ending a session switches off what it touched: devices and automation rules. */
    public function test_ending_a_session_suspends_its_devices_and_switches_off_its_rules(): void
    {
        $grant = $this->ask('write', 2);
        $this->approve($grant);
        $this->enter($grant);
        $principal = $this->inTenant(fn () => User::query()->where('support_grant_id', $grant)->sole());
        [$device, $rule] = $this->inTenant(fn () => app(AuditContext::class)->actingAs($principal->id, [], function () use ($principal) {
            $device = Device::query()->create(['location_id' => $this->locationA->id, 'name' => 'Till 9', 'code' => 'T9', 'status' => Device::STATUS_ACTIVE]);
            $rule = new AutomationRule(['name' => 'Left behind', 'document_type' => 'core.party', 'trigger_type' => 'schedule', 'trigger' => ['every' => 'day'], 'actions' => [], 'enabled' => true]);
            $rule->forceFill(['created_by' => $principal->id])->save();

            return [$device, $rule];
        }));

        $this->postJson("/api/v1/support-access/{$grant}/revoke", [], $this->headersFor())->assertOk();

        $this->assertSame(Device::STATUS_SUSPENDED, $this->inTenant(fn () => $device->fresh()->status));
        $this->assertFalse($this->inTenant(fn () => $rule->fresh()->enabled));
    }

    /** Review H2: deactivating or re-roling the staff member ends their access in every tenant, at once. */
    public function test_a_staff_member_deactivated_or_re_roled_loses_support_access_everywhere(): void
    {
        $grant = $this->ask();
        $this->approve($grant);
        [$session] = $this->enter($grant);
        $this->getJson('/api/v1/parties', $session)->assertOk();

        // A role without support access, even changed behind the console's back, refuses the session.
        PlatformUser::query()->whereKey($this->agent->id)->update(['role' => PlatformUser::ROLE_SALES]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/parties', $session)->assertUnauthorized();
        PlatformUser::query()->whereKey($this->agent->id)->update(['role' => PlatformUser::ROLE_SUPPORT]);

        $owner = $this->staffBearer($this->staff(PlatformUser::ROLE_OWNER));
        $this->postJson("/api/v1/platform/staff/{$this->agent->id}/deactivate", [], $owner)->assertOk();

        $this->assertSame(SupportAccessGrant::ENDED, $this->inTenant(fn () => SupportAccessGrant::query()->findOrFail($grant)->status));
        $this->assertSame('staff_changed', $this->inTenant(fn () => SupportAccessGrant::query()->findOrFail($grant)->end_reason));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/parties', $session)->assertUnauthorized();
    }
}
