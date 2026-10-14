<?php

namespace Modules\HR\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Pin\Pins;
use App\Core\Rbac\Scope;
use App\Core\Sync\DeviceSecrets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\HR\Events\AttendancePeriodClosed;
use Modules\HR\Models\AttendanceDay;
use Modules\HR\Models\Employee;
use Modules\HR\Models\Punch;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HR-20 to HR-26: punches from HR, the web, the till (PIN checked
// offline, uploads idempotent) and biometric devices (their own token);
// timesheets from the roster and the time rules; period close and its
// event; a closed period stays closed.
class AttendanceTest extends TestCase
{
    use BuildsHr, BuildsTill, RefreshTenantDatabase;

    private Employee $amina;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpHr();
        $this->amina = $this->employee(['location_id' => $this->locationA->id, 'clock_methods' => ['till', 'web', 'device'], 'biometric_ref' => '1001']);
        $this->postJson('/api/v1/hr/time-rules', [
            'company_id' => $this->acme->id, 'effective_from' => '2026-01-01', 'daily_overtime_after_minutes' => 480,
            'weekly_overtime_after_minutes' => 2700, 'night_start' => '22:00', 'night_end' => '06:00', 'late_grace_minutes' => 10, 'rest_days' => ['sun'],
        ], $this->headersFor($this->hrOfficer))->assertCreated();
    }

    public function test_a_planned_shift_and_manual_punches_make_an_approved_day_and_the_period_closes_with_its_totals(): void
    {
        Event::fake([AttendancePeriodClosed::class]);
        $shift = $this->postJson('/api/v1/hr/shifts', ['company_id' => $this->acme->id, 'code' => 'DAY', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60], $this->headersFor($this->hrOfficer))
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->amina->id, 'date' => '2026-10-05', 'shift_id' => $shift], $this->headersFor($this->managerA))->assertCreated();
        $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->amina->id, 'date' => '2026-10-06', 'shift_id' => $shift], $this->headersFor($this->managerA))->assertCreated();

        foreach ([['in', '2026-10-05T08:20:00+03:00'], ['out', '2026-10-05T18:00:00+03:00']] as [$kind, $at]) {
            $this->postJson('/api/v1/hr/punches', ['employee_id' => $this->amina->id, 'kind' => $kind, 'occurred_at' => $at, 'note' => 'Card reader down'], $this->headersFor($this->managerA))->assertCreated();
        }

        $days = $this->getJson('/api/v1/hr/timesheets?from=2026-10-01&to=2026-10-31', $this->headersFor($this->managerA))->assertOk()->json('data');
        $this->assertCount(2, $days);
        $this->assertSame(['present', ['late'], 520, 480, 40, 20], [$days[0]['status'], $days[0]['exceptions'], $days[0]['worked_minutes'], $days[0]['regular_minutes'], $days[0]['overtime_minutes'], $days[0]['late_minutes']]);
        // The 6th's shift ended with nobody clocking in.
        $this->assertSame(['absent', ['absent']], [$days[1]['status'], $days[1]['exceptions']]);
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.punch.create')->exists()));

        // Close: both days must be approved first.
        $period = $this->postJson('/api/v1/hr/attendance-periods', ['company_id' => $this->acme->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/hr/attendance-periods/{$period}/close", [], $this->headersFor($this->hrOfficer))->assertUnprocessable()->assertJsonPath('code', 'days_unapproved')->assertJsonPath('count', 2);
        $this->postJson("/api/v1/hr/attendance-periods/{$period}/close", [], $this->headersFor($this->managerA))->assertForbidden();

        $ids = $this->inTenant(fn () => AttendanceDay::query()->orderBy('work_date')->pluck('id')->all());
        $this->assertSame('absent', $this->inTenant(fn () => AttendanceDay::query()->find($ids[1])->status));
        $this->postJson('/api/v1/hr/timesheets/approve', ['day_ids' => $ids], $this->headersFor($this->managerA))->assertOk()->assertJsonPath('data.approved', 2);

        $closed = $this->postJson("/api/v1/hr/attendance-periods/{$period}/close", [], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.status', 'closed')->json('data');
        $this->assertEquals([['employee_id' => $this->amina->id, 'days_present' => 1, 'days_absent' => 1, 'days_leave' => 0, 'worked_minutes' => 520, 'regular_minutes' => 480, 'overtime_minutes' => 40, 'night_minutes' => 0, 'holiday_minutes' => 0, 'rest_day_minutes' => 0, 'late_minutes' => 20, 'early_leave_minutes' => 0]], $closed['totals']);
        Event::assertDispatched(AttendancePeriodClosed::class, fn (AttendancePeriodClosed $e) => $e->periodId === $period && $e->employees[0]['overtime_minutes'] === 40 && $e->timezone === 'Africa/Nairobi');

        // HR-22: a late punch into the closed period is kept, flagged, and changes nothing there.
        $late = $this->postJson('/api/v1/hr/punches', ['employee_id' => $this->amina->id, 'kind' => 'in', 'occurred_at' => '2026-10-06T08:00:00+03:00', 'note' => 'Forgot'], $this->headersFor($this->hrOfficer))
            ->assertCreated()->json('data');
        $this->assertSame(['period_closed'], $late['flags']);
        $this->assertSame('absent', $this->inTenant(fn () => AttendanceDay::query()->find($ids[1])->status));
        $this->postJson('/api/v1/hr/timesheets/reopen', ['day_ids' => [$ids[0]]], $this->headersFor($this->hrOfficer))->assertStatus(409)->assertJsonPath('code', 'period_closed');
    }

    public function test_a_period_cannot_close_without_a_time_rule_in_force_every_day(): void
    {
        $this->postJson('/api/v1/hr/time-rules', ['company_id' => $this->acme->id, 'effective_from' => '2026-06-01'], $this->headersFor($this->hrOfficer))
            ->assertUnprocessable()->assertJsonPath('code', 'time_rule_overlap');

        $period = $this->postJson('/api/v1/hr/attendance-periods', ['company_id' => $this->acme->id, 'start_date' => '2025-12-20', 'end_date' => '2026-01-10'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/hr/attendance-periods/{$period}/close", [], $this->headersFor($this->hrOfficer))
            ->assertUnprocessable()->assertJsonPath('code', 'time_rules_missing');
        $this->postJson('/api/v1/hr/attendance-periods', ['company_id' => $this->acme->id, 'start_date' => '2026-01-05', 'end_date' => '2026-01-20'], $this->headersFor($this->hrOfficer))
            ->assertUnprocessable()->assertJsonPath('code', 'period_overlap');
    }

    public function test_the_web_clock_toggles_in_and_out_for_an_employee_who_may_use_it(): void
    {
        $user = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        $this->patchJson("/api/v1/hr/employees/{$this->amina->id}", ['user_id' => $user->id], $this->headersFor($this->hrOfficer))->assertOk();

        $this->postJson('/api/v1/hr/me/clock', [], $this->headersFor($user))->assertCreated()->assertJsonPath('data.kind', 'in')->assertJsonPath('data.source', 'web');
        $this->postJson('/api/v1/hr/me/clock', [], $this->headersFor($user))->assertCreated()->assertJsonPath('data.kind', 'out');
        $this->assertTrue($this->getJson('/api/v1/hr/me/attendance', $this->headersFor($user))->assertOk()->json('data.can_clock'));

        $this->patchJson("/api/v1/hr/employees/{$this->amina->id}", ['clock_methods' => ['till']], $this->headersFor($this->hrOfficer))->assertOk();
        $this->postJson('/api/v1/hr/me/clock', [], $this->headersFor($user))->assertForbidden()->assertJsonPath('code', 'clock_method_off');
    }

    public function test_the_till_checks_the_clock_pin_offline_and_its_uploads_are_idempotent(): void
    {
        $this->putJson("/api/v1/hr/employees/{$this->amina->id}/clock-pin", ['pin' => '4321'], $this->headersFor($this->managerA))->assertOk();
        $elsewhere = $this->employee(['first_name' => 'Elsewhere', 'branch_id' => $this->branchB->id, 'clock_methods' => ['till']]);
        $this->putJson("/api/v1/hr/employees/{$elsewhere->id}/clock-pin", ['pin' => '1111'], $this->headersFor($this->hrOfficer))->assertOk();
        $till = $this->pairTill($this->locationA);

        // HR-23: only the employees clocking in at this place, each with a verifier the till checks offline.
        $rows = $this->pull($till, ['hr_clock_staff'])->assertOk()->json('entities.hr_clock_staff.upserts');
        $this->assertSame([$this->amina->id], array_column($rows, 'id'));
        $pin = $rows[0]['pin'];
        $this->assertSame(Pins::SCHEME, $pin['scheme']);
        $key = hash_pbkdf2('sha256', '4321', DeviceSecrets::decode($pin['salt']), $pin['iterations'], 32, true);
        $this->assertSame($pin['verifier'], DeviceSecrets::encode(hash_hmac('sha256', "hr-clock:v1:{$this->amina->id}:".$key, DeviceSecrets::decode($till['secret']), true)));
        $this->assertArrayNotHasKey('pin_hash', $rows[0]);

        // Two punches queued offline, uploaded twice: stored once, answered the same.
        $in = (string) Str::uuid7();
        $body = ['punches' => [
            ['id' => $in, 'employee_id' => $this->amina->id, 'kind' => 'in', 'occurred_at' => '2026-10-13T07:58:00+03:00'],
            ['id' => (string) Str::uuid7(), 'employee_id' => $elsewhere->id, 'kind' => 'in', 'occurred_at' => '2026-10-13T08:00:00+03:00'],
        ]];
        $first = $this->postJson('/api/v1/hr/till/punches', $body, $this->deviceHeaders($till))->assertOk()->json('data');
        $this->assertSame(['stored', 'rejected'], array_column($first, 'status'));
        $this->assertSame('not_at_device', $first[1]['code']);
        $this->assertSame($first[0], $this->postJson('/api/v1/hr/till/punches', $body, $this->deviceHeaders($till))->assertOk()->json('data.0'));
        $this->assertSame(1, $this->inTenant(fn () => Punch::query()->count()));
        $this->assertSame(['till', $till['id']], $this->inTenant(fn () => [Punch::query()->find($in)->source, Punch::query()->find($in)->device_id]));

        // People's tokens cannot upload as a till.
        $this->postJson('/api/v1/hr/till/punches', $body, $this->headersFor())->assertForbidden();
    }

    public function test_a_biometric_device_pushes_with_its_own_token_and_auto_alternates(): void
    {
        $created = $this->postJson('/api/v1/hr/attendance-devices', ['location_id' => $this->locationA->id, 'name' => 'Gate reader', 'vendor' => 'ZK'], $this->headersFor($this->hrOfficer))
            ->assertCreated()->json();
        $headers = ['Authorization' => 'Bearer '.$created['meta']['token'], 'Accept' => 'application/json'];
        $this->assertArrayNotHasKey('token', $created['data']);

        $body = ['punches' => [
            ['external_id' => 'ev-1', 'ref' => '1001', 'kind' => 'auto', 'occurred_at' => '2026-10-13T08:01:00+03:00'],
            ['external_id' => 'ev-2', 'ref' => '1001', 'kind' => 'auto', 'occurred_at' => '2026-10-13T17:03:00+03:00'],
            ['external_id' => 'ev-3', 'ref' => '9999', 'kind' => 'in', 'occurred_at' => '2026-10-13T17:03:00+03:00'],
        ]];
        $results = $this->postJson('/api/v1/hr/device/punches', $body, $headers)->assertOk()->json('data');
        $this->assertSame(['stored', 'stored', 'rejected'], array_column($results, 'status'));
        $this->assertSame(['in', 'out'], [$results[0]['kind'], $results[1]['kind']]);
        $this->assertSame('unknown_ref', $results[2]['code']);
        $this->assertSame(array_column($results, 'id'), array_column($this->postJson('/api/v1/hr/device/punches', $body, $headers)->assertOk()->json('data'), 'id'));
        $this->assertSame(2, $this->inTenant(fn () => Punch::query()->where('source', 'device')->count()));

        // The device token reaches nothing else, and a suspended device is refused.
        $this->getJson('/api/v1/hr/employees', $headers)->assertUnauthorized();
        $this->postJson("/api/v1/hr/attendance-devices/{$created['data']['id']}/suspend", [], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson('/api/v1/hr/device/punches', $body, $headers)->assertUnauthorized();

        $token = $this->postJson("/api/v1/hr/attendance-devices/{$created['data']['id']}/resume", [], $this->headersFor($this->hrOfficer))->assertOk();
        $rotated = $this->postJson("/api/v1/hr/attendance-devices/{$created['data']['id']}/token", [], $this->headersFor($this->hrOfficer))->assertOk()->json('data.token');
        $this->postJson('/api/v1/hr/device/punches', $body, $headers)->assertUnauthorized();
        $this->postJson('/api/v1/hr/device/punches', $body, ['Authorization' => 'Bearer '.$rotated, 'Accept' => 'application/json'])->assertOk();
    }

    public function test_voiding_a_punch_recomputes_its_day_and_is_audited(): void
    {
        $punch = $this->postJson('/api/v1/hr/punches', ['employee_id' => $this->amina->id, 'kind' => 'in', 'occurred_at' => CarbonImmutable::now()->subHour()->toIso8601String(), 'note' => 'Test'], $this->headersFor($this->hrOfficer))
            ->assertCreated()->json('data.id');
        $this->assertSame('incomplete', $this->inTenant(fn () => AttendanceDay::query()->sole()->status));

        $this->postJson("/api/v1/hr/punches/{$punch}/void", ['reason' => 'Wrong person'], $this->headersFor($this->hrOfficer))->assertOk()->assertJsonPath('data.void_reason', 'Wrong person');
        $this->assertSame(0, $this->inTenant(fn () => AttendanceDay::query()->count()));
        $this->postJson("/api/v1/hr/punches/{$punch}/void", ['reason' => 'Again'], $this->headersFor($this->hrOfficer))->assertStatus(409);
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'hr.punch.void')->exists()));
    }
}
