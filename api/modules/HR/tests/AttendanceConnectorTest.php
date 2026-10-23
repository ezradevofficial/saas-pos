<?php

namespace Modules\HR\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\Authenticate;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Punch;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HR-24, MKT-04, API-01..API-03 (ADR 020, ADR 024): the attendance device
// API on the public API, for the biometric connector and API keys. The
// active roster (id, name, enrolment reference; field rules; no sensitive
// group), the devices (never their tokens), and a device's punches through
// PunchUploads (resends answered the same, the offline window, matching by
// reference, only an active device); scopes, other tenants, app tokens and
// keys, rate limits.
class AttendanceConnectorTest extends TestCase
{
    use BuildsHr, BuildsMarketplace, RefreshTenantDatabase;

    private const SCOPES = ['hr.attendance.write', 'hr.device.read', 'hr.employee.read'];

    private Role $connectorRole;

    private Employee $amina;

    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpHr();
        $this->amina = $this->employee(['location_id' => $this->locationA->id, 'clock_methods' => ['device'], 'biometric_ref' => '1001', 'date_of_birth' => '1990-04-02', 'personal_phone' => '+254722000111']);
        $this->connectorRole = $this->inTenant(fn () => $this->role('Attendance connector', ['hr.employee.view', 'hr.device.view', 'hr.attendance.manage']));
        $this->deviceId = $this->postJson('/api/v1/hr/attendance-devices', ['location_id' => $this->locationA->id, 'name' => 'Gate reader', 'vendor' => 'ZK'], $this->headersFor($this->hrOfficer))
            ->assertCreated()->json('data.id');
    }

    /** @return array<string, string> bearer headers of a new API key */
    private function key(array $scopes = self::SCOPES, ?string $roleId = null, ?array $owner = null): array
    {
        $secret = $this->postJson('/api/v1/api-keys', ['name' => 'Terminal bridge', 'role_id' => $roleId ?? $this->connectorRole->id, 'scope_type' => 'tenant', 'scopes' => $scopes],
            $owner ?? $this->headersFor())->assertCreated()->json('secret');

        return ['Authorization' => 'Bearer '.$secret, 'Accept' => 'application/json'];
    }

    /** @return array<string, string> bearer headers of an app installed with the connector role */
    private function appToken(array $scopes = self::SCOPES): array
    {
        $app = $this->listedApp(['name' => 'Terminals', 'scopes' => $scopes]);
        $pkce = $this->pkce();
        $approved = $this->approve($app['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->connectorRole->id, 'scope_type' => 'tenant', 'scope_id' => null])->assertOk();

        return ['Authorization' => 'Bearer '.$this->exchange(self::codeFrom($approved->json('data.redirect_to')), $pkce['verifier'], $app)->assertOk()->json('access_token'), 'Accept' => 'application/json'];
    }

    private function at(string $time, int $daysAgo = 1): string
    {
        return CarbonImmutable::now('Africa/Nairobi')->subDays($daysAgo)->setTimeFromTimeString($time)->toIso8601String();
    }

    private function punches(array $headers, array $punches, ?string $device = null)
    {
        return $this->postJson('/api/v1/hr/connector/devices/'.($device ?? $this->deviceId).'/punches', ['punches' => $punches], $headers);
    }

    public function test_the_roster_lists_active_employees_with_their_reference_only(): void
    {
        $left = $this->employee(['first_name' => 'Baraka', 'biometric_ref' => '1002']);
        $this->postJson("/api/v1/hr/employees/{$left->id}/terminate", ['termination_date' => '2026-06-30', 'reason' => 'resignation'], $this->headersFor($this->hrOfficer))->assertOk();
        $headers = $this->key();

        $roster = $this->getJson('/api/v1/hr/connector/employees', $headers)->assertOk()->assertHeader('RateLimit-Limit');
        $this->assertSame([['id' => $this->amina->id, 'name' => 'Amina Otieno', 'biometric_ref' => '1001']], $roster->json('data'));
        $this->assertSame(['data', 'links', 'meta'], array_keys($roster->json()));
        foreach (['1990-04-02', '+254722000111', 'date_of_birth', 'pay', 'identifiers'] as $never) {
            $this->assertStringNotContainsString($never, (string) $roster->getContent());
        }

        // A key held at branch B reaches no employee of branch A.
        $branchKey = $this->postJson('/api/v1/api-keys', ['name' => 'Branch B', 'role_id' => $this->connectorRole->id, 'scope_type' => 'branch', 'scope_id' => $this->branchB->id, 'scopes' => self::SCOPES], $this->headersFor())->assertCreated()->json('secret');
        $this->assertSame([], $this->getJson('/api/v1/hr/connector/employees', ['Authorization' => 'Bearer '.$branchKey, 'Accept' => 'application/json'])->assertOk()->json('data'));
    }

    public function test_field_rules_hide_the_reference_or_the_name(): void
    {
        $this->inTenant(fn () => FieldRule::create(['role_id' => $this->connectorRole->id, 'resource' => 'hr_employee', 'field' => 'biometric_ref', 'mode' => 'hidden']));
        $this->assertSame([['id' => $this->amina->id, 'name' => 'Amina Otieno']], $this->getJson('/api/v1/hr/connector/employees', $this->key())->assertOk()->json('data'));

        $this->inTenant(fn () => FieldRule::create(['role_id' => $this->connectorRole->id, 'resource' => 'hr_employee', 'field' => 'last_name', 'mode' => 'hidden']));
        $this->assertSame([['id' => $this->amina->id]], $this->getJson('/api/v1/hr/connector/employees', $this->key())->assertOk()->json('data'));
    }

    public function test_devices_are_listed_without_their_tokens(): void
    {
        $devices = $this->getJson('/api/v1/hr/connector/devices', $this->key())->assertOk();
        $this->assertSame([$this->deviceId], array_column($devices->json('data'), 'id'));
        $this->assertSame(['id', 'name', 'company_id', 'location', 'status', 'last_seen_at'], array_keys($devices->json('data.0')));
        $this->assertSame(['Gate reader', 'Outlet A', 'active'], [$devices->json('data.0.name'), $devices->json('data.0.location.name'), $devices->json('data.0.status')]);
    }

    public function test_an_app_posts_punches_through_the_device_service_idempotently(): void
    {
        $headers = $this->appToken();
        $body = [
            ['external_id' => 'ev-1', 'ref' => '1001', 'kind' => 'auto', 'occurred_at' => $this->at('08:01')],
            ['external_id' => 'ev-2', 'ref' => '1001', 'kind' => 'auto', 'occurred_at' => $this->at('17:03')],
            ['external_id' => 'ev-3', 'ref' => '9999', 'kind' => 'in', 'occurred_at' => $this->at('17:03')],
            ['external_id' => 'ev-4', 'ref' => '1001', 'kind' => 'in', 'occurred_at' => $this->at('08:00', 9)],
        ];

        $results = $this->punches($headers, $body)->assertOk()->json('data');
        $this->assertSame(['stored', 'stored', 'rejected', 'rejected'], array_column($results, 'status'));
        $this->assertSame(['in', 'out'], [$results[0]['kind'], $results[1]['kind']]);
        $this->assertSame(['unknown_ref', 'punch_out_of_window'], [$results[2]['code'], $results[3]['code']]);

        // Sent again: the same punches, nothing new.
        $this->assertSame(array_column($results, 'id'), array_column($this->punches($headers, $body)->assertOk()->json('data'), 'id'));
        $this->inTenant(function () {
            $this->assertSame(2, Punch::query()->count());
            $this->assertSame([$this->deviceId], Punch::query()->distinct()->pluck('attendance_device_id')->all());
            $this->assertSame(['device'], Punch::query()->distinct()->pluck('source')->all());
        });

        // Validation as the device's own path.
        $this->punches($headers, [['external_id' => 'ev-5', 'ref' => '1001', 'kind' => 'sideways', 'occurred_at' => 'yesterday']])->assertUnprocessable()
            ->assertJsonValidationErrors(['punches.0.kind', 'punches.0.occurred_at']);

        // A suspended device takes no punches.
        $this->postJson("/api/v1/hr/attendance-devices/{$this->deviceId}/suspend", [], $this->headersFor($this->hrOfficer))->assertOk();
        $this->punches($headers, [['external_id' => 'ev-6', 'ref' => '1001', 'kind' => 'in', 'occurred_at' => $this->at('09:00')]])->assertStatus(409)->assertJsonPath('code', 'device_not_active');
    }

    public function test_scopes_and_permissions_are_required(): void
    {
        $punch = [['external_id' => 'ev-1', 'ref' => '1001', 'kind' => 'in', 'occurred_at' => $this->at('08:00')]];

        $readOnly = $this->key(['hr.attendance.read', 'hr.device.read', 'hr.employee.read']);
        $this->getJson('/api/v1/hr/connector/devices', $readOnly)->assertOk();
        $this->punches($readOnly, $punch)->assertForbidden()->assertJsonPath('code', 'api_scope_missing')->assertJsonPath('scope', 'hr.attendance.write');

        $noRoster = $this->key(['hr.attendance.write', 'hr.device.read']);
        $this->getJson('/api/v1/hr/connector/employees', $noRoster)->assertForbidden()->assertJsonPath('code', 'api_scope_missing')->assertJsonPath('scope', 'hr.employee.read');
        $this->getJson('/api/v1/hr/connector/devices', $this->appToken(['hr.employee.read']))->assertForbidden()->assertJsonPath('code', 'api_scope_missing');

        // A role that reads HR but does not manage attendance: sees the device, may not punch.
        $auditor = $this->key(self::SCOPES, $this->roles->get('read_only_auditor')->id);
        $this->getJson('/api/v1/hr/connector/devices', $auditor)->assertOk();
        $this->punches($auditor, $punch)->assertForbidden();
        $this->assertSame(0, $this->inTenant(fn () => Punch::query()->count()));
    }

    public function test_another_tenant_sees_nothing_and_cannot_punch(): void
    {
        $other = $this->otherTenant();
        [$token, $roleId] = $this->asTenant($other['user']->tenant_id, function () use ($other) {
            app(ModuleRegistry::class)->activate('hr');

            return [app(Authenticate::class)->issueToken($other['user'], '127.0.0.1', 'tests'),
                $this->role('Attendance connector', ['hr.employee.view', 'hr.device.view', 'hr.attendance.manage'])->id];
        });
        $headers = $this->key(self::SCOPES, $roleId, $this->bearer($token));

        $this->assertSame([], $this->getJson('/api/v1/hr/connector/employees', $headers)->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/hr/connector/devices?status=all', $headers)->assertOk()->json('data'));
        $this->punches($headers, [['external_id' => 'ev-1', 'ref' => '1001', 'kind' => 'in', 'occurred_at' => $this->at('08:00')]])->assertNotFound();
        $this->assertSame(0, $this->inTenant(fn () => Punch::query()->count()));
    }

    public function test_reads_and_punches_are_rate_limited_per_caller(): void
    {
        config(['hr.connector.reads_per_minute' => 1, 'hr.connector.punches_per_minute' => 1]);
        $headers = $this->key();

        $this->getJson('/api/v1/hr/connector/employees', $headers)->assertOk();
        $this->getJson('/api/v1/hr/connector/employees', $headers)->assertStatus(429);
        // Each route has its own window, and each caller.
        $this->getJson('/api/v1/hr/connector/devices', $headers)->assertOk();
        $this->getJson('/api/v1/hr/connector/employees', $this->key())->assertOk();

        $punch = [['external_id' => 'ev-1', 'ref' => '1001', 'kind' => 'in', 'occurred_at' => $this->at('08:00')]];
        $this->punches($headers, $punch)->assertOk();
        $this->punches($headers, $punch)->assertStatus(429);
    }

    /** Security review H2: people read within their role, but only keys and apps send a device's punches. */
    public function test_people_read_but_never_send_device_punches(): void
    {
        $this->getJson('/api/v1/hr/connector/employees', $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.0.biometric_ref', '1001');
        $punch = [['external_id' => 'ev-1', 'ref' => '1001', 'kind' => 'in', 'occurred_at' => $this->at('08:00')]];

        foreach ([$this->hrOfficer, $this->owner] as $person) {
            $this->punches($this->headersFor($person), $punch)->assertForbidden()->assertJsonPath('code', 'device_punches_keys_only');
        }
        // A branch manager of B does not reach the device at A at all.
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->punches($this->headersFor($managerB), $punch)->assertNotFound();
        $this->assertSame(0, $this->inTenant(fn () => Punch::query()->count()));
    }

    /**
     * Security review H2: a reference matches only someone at the device's place (its location, or
     * its branch for someone placed at the branch); anyone else in the company is answered exactly
     * as an unknown reference, so the route finds no one. Punches record the principal, and each
     * upload is audited once with the device, the counts and the time range.
     */
    public function test_punches_match_only_people_at_the_devices_place_recorded_and_audited(): void
    {
        $this->employee(['first_name' => 'Bahati', 'location_id' => $this->locationB->id, 'branch_id' => $this->branchB->id, 'biometric_ref' => '2002']);
        $atBranch = $this->employee(['first_name' => 'Chausiku', 'biometric_ref' => '3003']);
        $headers = $this->key();
        $body = [
            ['external_id' => 'ev-1', 'ref' => '1001', 'kind' => 'in', 'occurred_at' => $this->at('08:00')],
            ['external_id' => 'ev-2', 'ref' => '2002', 'kind' => 'in', 'occurred_at' => $this->at('08:05')],
            ['external_id' => 'ev-3', 'ref' => '9999', 'kind' => 'in', 'occurred_at' => $this->at('08:10')],
            ['external_id' => 'ev-4', 'ref' => '3003', 'kind' => 'in', 'occurred_at' => $this->at('08:15')],
        ];

        $results = $this->punches($headers, $body)->assertOk()->json('data');
        $this->assertSame(['stored', 'rejected', 'rejected', 'stored'], array_column($results, 'status'));
        // Someone elsewhere in the company and no one at all answer the same.
        $this->assertSame(array_diff_key($results[1], ['external_id' => 1]), array_diff_key($results[2], ['external_id' => 1]));
        $this->assertSame('unknown_ref', $results[1]['code']);

        $principal = $this->inTenant(fn () => User::query()->where('status', 'service')->sole());
        $this->inTenant(function () use ($principal, $atBranch) {
            $this->assertSame([$this->amina->id, $atBranch->id], Punch::query()->orderBy('occurred_at')->pluck('employee_id')->all());
            $this->assertSame([$principal->id], Punch::query()->distinct()->pluck('recorded_by')->all());
            $entry = AuditEntry::query()->where('action', 'hr.punch.device_upload')->sole();
            $this->assertSame([$principal->id, $this->deviceId], [$entry->user_id, $entry->auditable_id]);
            $this->assertSame([4, 2, 2, $this->deviceId, $principal->id], [$entry->after['count'], $entry->after['stored'], $entry->after['rejected'], $entry->after['attendance_device_id'], $entry->after['recorded_by']]);
            $this->assertSame([CarbonImmutable::parse($this->at('08:00'))->utc()->toIso8601String(), CarbonImmutable::parse($this->at('08:15'))->utc()->toIso8601String()], [$entry->after['from'], $entry->after['to']]);
            $this->assertNotNull($entry->after['metadata']['api_key_id'] ?? null);
        });
    }
}
