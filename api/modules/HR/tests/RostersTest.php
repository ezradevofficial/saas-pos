<?php

namespace Modules\HR\Tests;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Calendar\BusinessCalendar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Modules\HR\Models\RosterShift;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HR-16 to HR-19: shifts and rotations, rotating rosters generated per
// branch idempotently around approved leave, the week grid with public
// holidays, hand edits and shift swaps with the peer's acceptance and the
// branch manager's approval.
class RostersTest extends TestCase
{
    use BuildsHr, RefreshTenantDatabase;

    private string $day;

    private string $night;

    private string $rotation;

    private Employee $amina;

    private Employee $baraka;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpHr();
        $this->assertSame(0, Artisan::call('country-packs:holidays', ['code' => 'KE']));
        app(BusinessCalendar::class)->forgetHolidays();

        $this->day = $this->postJson('/api/v1/hr/shifts', ['company_id' => $this->acme->id, 'code' => 'D', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '16:00', 'break_minutes' => 30], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $this->night = $this->postJson('/api/v1/hr/shifts', ['company_id' => $this->acme->id, 'code' => 'N', 'name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        // Two days, two nights, two rest days.
        $this->rotation = $this->postJson('/api/v1/hr/rotations', ['company_id' => $this->acme->id, 'name' => '2-2-2', 'days' => [$this->day, $this->day, $this->night, $this->night, null, null]], $this->headersFor($this->hrOfficer))
            ->assertCreated()->assertJsonPath('data.cycle_days', 6)->json('data.id');
        $this->amina = $this->employee(['first_name' => 'Amina']);
        $this->baraka = $this->employee(['first_name' => 'Baraka']);
    }

    public function test_a_rotating_roster_generates_shifts_by_offset_idempotently_and_around_approved_leave(): void
    {
        $roster = $this->postJson('/api/v1/hr/rosters', [
            'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Branch A floor', 'rotation_id' => $this->rotation, 'anchor_date' => '2026-11-02',
            'members' => [['employee_id' => $this->amina->id, 'offset_days' => 0], ['employee_id' => $this->baraka->id, 'offset_days' => 2]],
        ], $this->headersFor($this->managerA))->assertCreated()->assertJsonCount(2, 'data.members')->json('data.id');

        // Approved leave for Amina on 4 November.
        $this->approvedLeave($this->amina, '2026-11-04');

        $first = $this->postJson("/api/v1/hr/rosters/{$roster}/generate", ['from' => '2026-11-02', 'to' => '2026-11-07'], $this->headersFor($this->managerA))->assertOk()->json('data');
        // Amina: D D N(leave) N - -; Baraka (offset 2): N N - - D D.
        $this->assertSame(['created' => 7, 'kept' => 0, 'leave' => 1, 'closed' => 0], $first);
        $this->assertSame(['2026-11-02' => 'D', '2026-11-03' => 'D', '2026-11-05' => 'N'], $this->planned($this->amina));
        $this->assertSame(['2026-11-02' => 'N', '2026-11-03' => 'N', '2026-11-06' => 'D', '2026-11-07' => 'D'], $this->planned($this->baraka));

        // A night shift ends the next morning (Africa/Nairobi), as UTC instants.
        $night = $this->inTenant(fn () => RosterShift::query()->where('employee_id', $this->amina->id)->where('date', '2026-11-05')->sole());
        $this->assertSame(['2026-11-05T19:00:00+00:00', '2026-11-06T03:00:00+00:00'], [$night->start_at->toIso8601String(), $night->end_at->toIso8601String()]);

        // Generating again changes nothing; a hand-placed shift is kept.
        $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->amina->id, 'date' => '2026-11-08', 'shift_id' => $this->night], $this->headersFor($this->managerA))->assertCreated();
        $again = $this->postJson("/api/v1/hr/rosters/{$roster}/generate", ['from' => '2026-11-02', 'to' => '2026-11-08'], $this->headersFor($this->managerA))->assertOk()->json('data');
        $this->assertSame(['created' => 1, 'kept' => 8, 'leave' => 1, 'closed' => 0], $again);

        // The range is limited.
        $this->postJson("/api/v1/hr/rosters/{$roster}/generate", ['from' => '2026-01-01', 'to' => '2026-12-31'], $this->headersFor($this->managerA))->assertUnprocessable()->assertJsonPath('code', 'range_too_long');
        // A branch-B manager cannot touch branch A's roster.
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->postJson("/api/v1/hr/rosters/{$roster}/generate", ['from' => '2026-11-02', 'to' => '2026-11-03'], $this->headersFor($managerB))->assertForbidden();
    }

    public function test_the_week_grid_marks_public_holidays_and_hand_edits_refuse_two_shifts_a_day(): void
    {
        // Tuesday 20 October 2026 is Mashujaa Day (country pack).
        $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->amina->id, 'date' => '2026-10-20', 'shift_id' => $this->day], $this->headersFor($this->managerA))->assertCreated();
        $planned = $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->amina->id, 'date' => '2026-10-21', 'shift_id' => $this->day], $this->headersFor($this->managerA))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->amina->id, 'date' => '2026-10-21', 'shift_id' => $this->night], $this->headersFor($this->managerA))
            ->assertUnprocessable()->assertJsonPath('code', 'shift_clash');
        $this->patchJson("/api/v1/hr/roster-shifts/{$planned}", ['date' => '2026-10-20'], $this->headersFor($this->managerA))->assertUnprocessable()->assertJsonPath('code', 'shift_clash');

        $grid = $this->getJson('/api/v1/hr/roster-shifts?from=2026-10-19&to=2026-10-25', $this->headersFor($this->managerA))->assertOk()->json('data');
        $this->assertSame([['2026-10-20', true], ['2026-10-21', false]], array_map(fn ($s) => [$s['date'], $s['holiday']], $grid));

        $this->postJson("/api/v1/hr/roster-shifts/{$planned}/cancel", [], $this->headersFor($this->managerA))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertCount(1, $this->getJson('/api/v1/hr/roster-shifts?from=2026-10-19&to=2026-10-25', $this->headersFor($this->managerA))->json('data'));
        $this->getJson('/api/v1/hr/roster-shifts?from=2026-10-01&to=2026-12-31', $this->headersFor($this->managerA))->assertUnprocessable()->assertJsonPath('code', 'range_too_long');
    }

    public function test_a_swap_is_accepted_by_the_peer_approved_by_the_branch_manager_and_moves_the_shifts(): void
    {
        $aminaUser = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        $barakaUser = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        $this->patchJson("/api/v1/hr/employees/{$this->amina->id}", ['user_id' => $aminaUser->id], $this->headersFor($this->hrOfficer))->assertOk();
        $this->patchJson("/api/v1/hr/employees/{$this->baraka->id}", ['user_id' => $barakaUser->id], $this->headersFor($this->hrOfficer))->assertOk();
        $mine = $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->amina->id, 'date' => '2026-11-10', 'shift_id' => $this->day], $this->headersFor($this->managerA))->assertCreated()->json('data.id');
        $theirs = $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->baraka->id, 'date' => '2026-11-11', 'shift_id' => $this->night], $this->headersFor($this->managerA))->assertCreated()->json('data.id');

        // Self-service lists the colleagues and their coming shifts to swap with.
        $peers = $this->getJson('/api/v1/hr/me/swap-peers', $this->headersFor($aminaUser))->assertOk()->json('data');
        $this->assertSame([[$this->baraka->id, [$theirs]]], array_map(fn ($p) => [$p['id'], array_column($p['shifts'], 'id')], $peers));

        // Amina asks in self-service; only Baraka (the peer) answers.
        $swap = $this->postJson('/api/v1/hr/me/swaps', ['roster_shift_id' => $mine, 'peer_employee_id' => $this->baraka->id, 'peer_roster_shift_id' => $theirs, 'reason' => 'Exam'], $this->headersFor($aminaUser))
            ->assertCreated()->assertJsonPath('data.status', 'awaiting_peer')->json('data.id');
        $this->postJson("/api/v1/hr/me/swaps/{$swap}/accept", [], $this->headersFor($aminaUser))->assertNotFound();
        $this->postJson("/api/v1/hr/me/swaps/{$swap}/accept", [], $this->headersFor($barakaUser))->assertOk()->assertJsonPath('data.status', 'pending');

        // The branch manager approves (never the requester, APR-07).
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $swap)->sole());
        $this->assertSame([$this->managerA->id], $this->inTenant(fn () => ApprovalAssignment::query()->where('request_id', $approval->id)->pluck('user_id')->all()));
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($this->managerA))->assertOk();

        $this->getJson("/api/v1/hr/shift-swaps/{$swap}", $this->headersFor($this->managerA))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(['2026-11-11' => 'N'], $this->planned($this->amina));
        $this->assertSame(['2026-11-10' => 'D'], $this->planned($this->baraka));
        $this->assertSame(['swap', 'swap'], $this->inTenant(fn () => RosterShift::query()->where('status', 'planned')->orderBy('date')->pluck('source')->all()));

        // A past shift cannot be swapped.
        $past = $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->amina->id, 'date' => '2026-10-01', 'shift_id' => $this->day], $this->headersFor($this->managerA))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/hr/me/swaps', ['roster_shift_id' => $past, 'peer_employee_id' => $this->baraka->id], $this->headersFor($aminaUser))
            ->assertUnprocessable()->assertJsonPath('code', 'swap_shift_invalid');
    }

    /** @return array<string, string> date => shift code of the employee's planned shifts */
    private function planned(Employee $employee): array
    {
        return $this->inTenant(fn () => RosterShift::query()->with('shift')->where('employee_id', $employee->id)->where('status', 'planned')->orderBy('date')->get()
            ->mapWithKeys(fn (RosterShift $s) => [$s->date->toDateString() => $s->shift->code])->all());
    }

    private function approvedLeave(Employee $employee, string $date): void
    {
        $this->inTenant(function () use ($employee, $date) {
            $type = LeaveType::create(['company_id' => $this->acme->id, 'code' => 'X', 'name' => 'X', 'accrual' => 'none', 'count_basis' => 'calendar_days']);
            LeaveRequest::create([
                'number' => 'LV-T-1', 'employee_id' => $employee->id, 'company_id' => $employee->company_id, 'branch_id' => $employee->branch_id, 'leave_type_id' => $type->id,
                'start_date' => $date, 'end_date' => $date, 'days' => '1', 'dates' => [['date' => $date, 'fraction' => '1.00']], 'status' => 'approved', 'requested_by' => $this->owner->id,
            ]);
        });
    }
}
