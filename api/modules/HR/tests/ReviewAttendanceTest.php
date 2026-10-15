<?php

namespace Modules\HR\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Pin\Pins;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\HR\Models\AttendanceDay;
use Modules\HR\Models\Employee;
use Modules\HR\Models\EmployeeIdentifier;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\Punch;
use Modules\HR\Models\RosterShift;
use Modules\HR\Models\ShiftSwap;
use Modules\HR\Support\Digest;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Review fixes (HR-10, HR-19, HR-22 to HR-26): the digest key and rekey,
// punch bounds, clock-in PIN risks, night shifts, closed periods, swap
// staleness, out-punch pairing, roster generation, time rules, transfers.
class ReviewAttendanceTest extends TestCase
{
    use BuildsHr, BuildsTill, RefreshTenantDatabase;

    private Employee $amina;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpHr();
        $this->amina = $this->employee(['location_id' => $this->locationA->id, 'clock_methods' => ['till', 'web', 'device'], 'biometric_ref' => '1001']);
        $this->day = $this->postJson('/api/v1/hr/shifts', ['company_id' => $this->acme->id, 'code' => 'D', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '16:00'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
    }

    public function test_identifier_digests_use_their_own_key_and_are_rekeyed_by_the_command(): void
    {
        config(['hr.digest_key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->putJson("/api/v1/hr/employees/{$this->amina->id}/identifiers", ['identifiers' => [['type' => 'national_id', 'value' => '12345678']]], $this->headersFor($this->hrOfficer))->assertOk();
        $stored = $this->inTenant(fn () => EmployeeIdentifier::query()->sole()->value_digest);
        $this->assertSame(hash_hmac('sha256', 'national_id:12345678', str_repeat('k', 32)), $stored);

        config(['hr.digest_key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
        $this->assertSame(0, Artisan::call('hr:rekey-digests'));
        $this->assertSame(Digest::of('national_id', '12345678'), $this->inTenant(fn () => EmployeeIdentifier::query()->sole()->value_digest));
        $this->assertNotSame($stored, $this->inTenant(fn () => EmployeeIdentifier::query()->sole()->value_digest));
    }

    public function test_punches_are_bounded_by_the_employment_the_offset_and_the_offline_window(): void
    {
        $headers = $this->headersFor($this->hrOfficer);
        // Before the hire date (5 January 2026), and without an offset.
        $this->postJson('/api/v1/hr/punches', ['employee_id' => $this->amina->id, 'kind' => 'in', 'occurred_at' => '2025-12-20T08:00:00+03:00', 'note' => 'x'], $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'punch_outside_employment');
        $this->postJson('/api/v1/hr/punches', ['employee_id' => $this->amina->id, 'kind' => 'in', 'occurred_at' => '2026-10-13 08:00:00', 'note' => 'x'], $headers)->assertUnprocessable();

        // The till's offline window: 7 days back, one hour ahead.
        $till = $this->pairTill($this->locationA);
        $old = (string) Str::uuid7();
        $results = $this->postJson('/api/v1/hr/till/punches', ['punches' => [
            ['id' => $old, 'employee_id' => $this->amina->id, 'kind' => 'in', 'occurred_at' => now()->subDays(8)->toIso8601String()],
            ['id' => (string) Str::uuid7(), 'employee_id' => $this->amina->id, 'kind' => 'in', 'occurred_at' => now()->addHours(2)->toIso8601String()],
        ]], $this->deviceHeaders($till))->assertOk()->json('data');
        $this->assertSame(['punch_out_of_window', 'punch_out_of_window'], array_column($results, 'code'));

        // A device only matches active employees.
        $created = $this->postJson('/api/v1/hr/attendance-devices', ['location_id' => $this->locationA->id, 'name' => 'Gate'], $headers)->assertCreated()->json();
        $device = ['Authorization' => 'Bearer '.$created['meta']['token'], 'Accept' => 'application/json'];
        $this->postJson("/api/v1/hr/employees/{$this->amina->id}/terminate", ['termination_date' => now()->subDays(3)->toDateString(), 'reason' => 'resignation'], $headers)->assertOk();
        $this->assertSame('unknown_ref', $this->postJson('/api/v1/hr/device/punches', ['punches' => [['external_id' => 'e1', 'ref' => '1001', 'kind' => 'in', 'occurred_at' => now()->subHour()->toIso8601String()]]], $device)
            ->json('data.0.code'));

        // A punch the till queued before she left still counts; one after is refused.
        $results = $this->postJson('/api/v1/hr/till/punches', ['punches' => [
            ['id' => (string) Str::uuid7(), 'employee_id' => $this->amina->id, 'kind' => 'in', 'occurred_at' => now()->subDays(4)->setTime(9, 0)->toIso8601String()],
            ['id' => (string) Str::uuid7(), 'employee_id' => $this->amina->id, 'kind' => 'in', 'occurred_at' => now()->subDay()->toIso8601String()],
        ]], $this->deviceHeaders($till))->assertOk()->json('data');
        $this->assertSame(['stored', 'rejected'], array_column($results, 'status'));
    }

    public function test_a_moved_employees_queued_offline_punches_at_the_old_place_are_kept(): void
    {
        $till = $this->pairTill($this->locationA);
        $this->patchJson("/api/v1/hr/employees/{$this->amina->id}", ['location_id' => $this->locationB->id], $this->headersFor($this->hrOfficer))->assertOk();

        $results = $this->postJson('/api/v1/hr/till/punches', ['punches' => [
            ['id' => (string) Str::uuid7(), 'employee_id' => $this->amina->id, 'kind' => 'in', 'occurred_at' => now()->subDays(2)->toIso8601String()],
            ['id' => (string) Str::uuid7(), 'employee_id' => $this->amina->id, 'kind' => 'out', 'occurred_at' => now()->addMinutes(5)->toIso8601String()],
        ]], $this->deviceHeaders($till))->assertOk()->json('data');
        $this->assertSame(['stored', 'rejected'], array_column($results, 'status'));
    }

    public function test_a_transfer_takes_pending_leave_future_shifts_and_open_swaps_along(): void
    {
        $peer = $this->employee(['first_name' => 'Peer', 'location_id' => $this->locationA->id]);
        $shift = $this->plan($this->amina, '2026-11-20');
        $swap = $this->postJson('/api/v1/hr/shift-swaps', ['employee_id' => $this->amina->id, 'roster_shift_id' => $shift, 'peer_employee_id' => $peer->id], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $type = $this->postJson('/api/v1/hr/leave-types', ['company_id' => $this->acme->id, 'code' => 'UL', 'name' => 'Unpaid', 'accrual' => 'none', 'count_basis' => 'calendar_days'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $leave = $this->postJson('/api/v1/hr/leave-requests', ['employee_id' => $this->amina->id, 'leave_type_id' => $type, 'start_date' => '2026-12-01', 'end_date' => '2026-12-01'], $this->headersFor($this->owner))->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/hr/employees/{$this->amina->id}", ['location_id' => $this->locationB->id], $this->headersFor($this->hrOfficer))->assertOk();

        $this->assertSame($this->branchB->id, $this->inTenant(fn () => LeaveRequest::query()->find($leave)->branch_id));
        $this->assertSame($this->locationB->id, $this->inTenant(fn () => RosterShift::query()->find($shift)->location_id));
        $this->assertSame($this->locationB->id, $this->inTenant(fn () => ShiftSwap::query()->find($swap)->location_id));
    }

    public function test_a_clock_in_pin_is_never_the_till_pin_and_till_lockouts_are_audited_and_alert_hr(): void
    {
        $user = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->inTenant(fn () => app(Pins::class)->set($user, '4321', null));
        $this->inTenant(fn () => Employee::query()->whereKey($this->amina->id)->update(['user_id' => $user->id]));
        $this->putJson("/api/v1/hr/employees/{$this->amina->id}/clock-pin", ['pin' => '4321'], $this->headersFor($this->hrOfficer))->assertUnprocessable()->assertJsonPath('code', 'clock_pin_same_as_till');
        $this->putJson("/api/v1/hr/employees/{$this->amina->id}/clock-pin", ['pin' => '9876'], $this->headersFor($this->hrOfficer))->assertOk();

        $till = $this->pairTill($this->locationA);
        $this->postJson('/api/v1/hr/till/clock-attempts', ['reports' => [
            ['employee_id' => $this->amina->id, 'failed_attempts' => 2, 'locked' => false, 'at' => now()->subHour()->toIso8601String()],
            ['employee_id' => $this->amina->id, 'failed_attempts' => 5, 'locked' => true, 'at' => now()->toIso8601String()],
        ]], $this->deviceHeaders($till))->assertOk()->assertJsonPath('data.1.recorded', true);

        $this->assertSame(['hr.employee.clock_pin_failures', 'hr.employee.clock_pin_locked'], $this->inTenant(fn () => AuditEntry::query()->whereIn('action', ['hr.employee.clock_pin_failures', 'hr.employee.clock_pin_locked'])->orderBy('seq')->pluck('action')->all()));
        $this->assertTrue($this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->hrOfficer->id)->where('event_type', 'hr.clock_pin.locked')->exists()));
    }

    public function test_a_night_shifts_out_after_midnight_closes_its_in_on_the_web_and_for_devices(): void
    {
        $created = $this->postJson('/api/v1/hr/attendance-devices', ['location_id' => $this->locationA->id, 'name' => 'Gate'], $this->headersFor($this->hrOfficer))->assertCreated()->json();
        $device = ['Authorization' => 'Bearer '.$created['meta']['token'], 'Accept' => 'application/json'];
        // Two nights ago, so the out (06:00) is never later than now plus 1 hour, whatever the time of day.
        $in = CarbonImmutable::now()->subDays(2)->setTimezone('Africa/Nairobi')->setTime(22, 0);
        $out = $in->addHours(8);

        $kinds = array_column($this->postJson('/api/v1/hr/device/punches', ['punches' => [
            ['external_id' => 'n1', 'ref' => '1001', 'kind' => 'auto', 'occurred_at' => $in->toIso8601String()],
            ['external_id' => 'n2', 'ref' => '1001', 'kind' => 'auto', 'occurred_at' => $out->toIso8601String()],
        ]], $device)->assertOk()->json('data'), 'kind');
        $this->assertSame(['in', 'out'], $kinds);

        // HR-25: the out pairs with its in: both on the night's work date, one day of 8 hours.
        $this->assertSame([$in->toDateString(), $in->toDateString()], $this->inTenant(fn () => Punch::query()->orderBy('occurred_at')->get()->map(fn (Punch $p) => $p->work_date->toDateString())->all()));
        $this->assertSame(480, $this->inTenant(fn () => AttendanceDay::query()->sole()->worked_minutes));
    }

    public function test_an_out_pairs_with_the_open_in_not_with_the_nearest_shift(): void
    {
        // A shift tomorrow morning starts 06:00; the night's out at 05:00 belongs to tonight's in.
        $early = $this->postJson('/api/v1/hr/shifts', ['company_id' => $this->acme->id, 'code' => 'E', 'name' => 'Early', 'start_time' => '06:00', 'end_time' => '14:00'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $night = CarbonImmutable::now()->subDays(2)->setTimezone('Africa/Nairobi')->setTime(21, 0);
        $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->amina->id, 'date' => $night->addDay()->toDateString(), 'shift_id' => $early], $this->headersFor($this->hrOfficer))->assertCreated();

        foreach ([['in', $night], ['out', $night->addHours(8)]] as [$kind, $at]) {
            $this->postJson('/api/v1/hr/punches', ['employee_id' => $this->amina->id, 'kind' => $kind, 'occurred_at' => $at->toIso8601String(), 'note' => 'Night'], $this->headersFor($this->hrOfficer))->assertCreated();
        }

        $this->assertSame([$night->toDateString()], $this->inTenant(fn () => Punch::query()->pluck('work_date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->unique()->values()->all()));
    }

    public function test_closed_periods_are_final_for_shifts_swaps_approvals_and_cannot_close_early(): void
    {
        $this->postJson('/api/v1/hr/time-rules', ['company_id' => $this->acme->id, 'effective_from' => '2026-01-01'], $this->headersFor($this->hrOfficer))->assertCreated();
        $future = $this->postJson('/api/v1/hr/attendance-periods', ['company_id' => $this->acme->id, 'start_date' => '2026-12-01', 'end_date' => '2026-12-31'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/hr/attendance-periods/{$future}/close", [], $this->headersFor($this->hrOfficer))->assertUnprocessable()->assertJsonPath('code', 'period_not_ended');

        $planned = $this->plan($this->amina, '2026-09-07');
        $period = $this->postJson('/api/v1/hr/attendance-periods', ['company_id' => $this->acme->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $days = $this->inTenant(fn () => AttendanceDay::query()->pluck('id')->all());
        $this->postJson('/api/v1/hr/timesheets/approve', ['day_ids' => $days], $this->headersFor($this->hrOfficer))->assertOk();
        $this->postJson("/api/v1/hr/attendance-periods/{$period}/close", [], $this->headersFor($this->hrOfficer))->assertOk();

        $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->amina->id, 'date' => '2026-09-08', 'shift_id' => $this->day], $this->headersFor($this->hrOfficer))->assertStatus(409)->assertJsonPath('code', 'period_closed');
        $this->postJson("/api/v1/hr/roster-shifts/{$planned}/cancel", [], $this->headersFor($this->hrOfficer))->assertStatus(409);
        $this->patchJson("/api/v1/hr/roster-shifts/{$planned}", ['date' => '2026-10-07'], $this->headersFor($this->hrOfficer))->assertStatus(409);

        // A roster generated over it plans nothing there.
        $rotation = $this->postJson('/api/v1/hr/rotations', ['company_id' => $this->acme->id, 'name' => 'Daily', 'days' => [$this->day]], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $roster = $this->postJson('/api/v1/hr/rosters', ['company_id' => $this->acme->id, 'name' => 'R', 'rotation_id' => $rotation, 'anchor_date' => '2026-09-01', 'members' => [['employee_id' => $this->amina->id]]], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $counts = $this->postJson("/api/v1/hr/rosters/{$roster}/generate", ['from' => '2026-09-25', 'to' => '2026-10-02'], $this->headersFor($this->hrOfficer))->assertOk()->json('data');
        $this->assertSame([2, 6], [$counts['created'], $counts['closed']]);
    }

    public function test_a_swap_is_stale_once_a_shift_changed_or_started(): void
    {
        $peer = $this->employee(['first_name' => 'Peer', 'location_id' => $this->locationA->id]);
        $mine = $this->plan($this->amina, '2026-11-20');
        $swap = $this->postJson('/api/v1/hr/shift-swaps', ['employee_id' => $this->amina->id, 'roster_shift_id' => $mine, 'peer_employee_id' => $peer->id], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');

        // The shift moves to another time the same day: the swap was asked on the old one.
        $late = $this->postJson('/api/v1/hr/shifts', ['company_id' => $this->acme->id, 'code' => 'L', 'name' => 'Late', 'start_time' => '14:00', 'end_time' => '22:00'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/hr/roster-shifts/{$mine}", ['shift_id' => $late], $this->headersFor($this->hrOfficer))->assertOk();
        $this->postJson("/api/v1/hr/shift-swaps/{$swap}/accept", [], $this->headersFor($this->hrOfficer))->assertStatus(409)->assertJsonPath('code', 'swap_stale');
    }

    public function test_generation_keeps_cancelled_shifts_cancelled_and_a_second_run_at_once_is_refused(): void
    {
        $rotation = $this->postJson('/api/v1/hr/rotations', ['company_id' => $this->acme->id, 'name' => 'Daily', 'days' => [$this->day]], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $roster = $this->postJson('/api/v1/hr/rosters', ['company_id' => $this->acme->id, 'name' => 'R', 'rotation_id' => $rotation, 'anchor_date' => '2026-11-02', 'members' => [['employee_id' => $this->amina->id]]], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $generate = fn () => $this->postJson("/api/v1/hr/rosters/{$roster}/generate", ['from' => '2026-11-02', 'to' => '2026-11-04'], $this->headersFor($this->hrOfficer));

        // Another run holds the roster (a second session): 409, not 500. (Taken before any run of
        // this test: the test's transaction keeps a run's lock until it ends.)
        $config = config('database.connections.pgsql_owner');
        $other = new \PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $this->assertTrue((bool) $other->query("select pg_try_advisory_lock(hashtext('hr-roster:' || '{$roster}'))")->fetchColumn());

        try {
            $generate()->assertStatus(409)->assertJsonPath('code', 'roster_busy');
        } finally {
            $other->query("select pg_advisory_unlock(hashtext('hr-roster:' || '{$roster}'))");
            $other = null;
        }

        $generate()->assertOk();
        $cancelled = $this->inTenant(fn () => RosterShift::query()->where('date', '2026-11-03')->value('id'));
        $this->postJson("/api/v1/hr/roster-shifts/{$cancelled}/cancel", [], $this->headersFor($this->hrOfficer))->assertOk();
        $this->assertSame(0, $generate()->assertOk()->json('data.created'));
        $this->assertSame(['planned', 'cancelled', 'planned'], $this->inTenant(fn () => RosterShift::query()->orderBy('date')->pluck('status')->all()));
    }

    public function test_a_time_rule_ending_before_it_starts_is_a_validation_error_and_a_duplicate_punch_at_once_is_stored(): void
    {
        $this->postJson('/api/v1/hr/time-rules', ['company_id' => $this->acme->id, 'effective_from' => '2026-06-01', 'effective_to' => '2026-05-01'], $this->headersFor($this->hrOfficer))
            ->assertUnprocessable()->assertJsonPath('code', 'time_rule_invalid');

        // Another request stores the same punch between our check and our insert.
        $till = $this->pairTill($this->locationA);
        $id = (string) Str::uuid7();
        $done = false;
        DB::listen(function ($query) use ($id, &$done) {
            if (! $done && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'hr_punches') && in_array($id, $query->bindings, true)) {
                $done = true;
                DB::connection('pgsql')->table('hr_punches')->insert([
                    'id' => $id, 'tenant_id' => app(TenantContext::class)->id(), 'employee_id' => $this->amina->id, 'company_id' => $this->acme->id, 'kind' => 'in',
                    'occurred_at' => now()->subHour(), 'source' => 'till', 'flags' => '[]', 'received_at' => now(), 'work_date' => now()->toDateString(),
                ]);
            }
        });

        $this->postJson('/api/v1/hr/till/punches', ['punches' => [['id' => $id, 'employee_id' => $this->amina->id, 'kind' => 'in', 'occurred_at' => now()->subHour()->toIso8601String()]]], $this->deviceHeaders($till))
            ->assertOk()->assertJsonPath('data.0.status', 'stored');
        $this->assertSame(1, $this->inTenant(fn () => Punch::query()->whereKey($id)->count()));
    }

    private function plan(Employee $employee, string $date): string
    {
        return $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $employee->id, 'date' => $date, 'shift_id' => $this->day], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
    }
}
