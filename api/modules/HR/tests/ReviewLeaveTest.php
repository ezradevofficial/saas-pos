<?php

namespace Modules\HR\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Modules\HR\Leave\Accruals;
use Modules\HR\Leave\LeaveLedger;
use Modules\HR\Models\AttendanceDay;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveEntry;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Modules\HR\Tests\Concerns\BuildsHr;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Review fixes (HR-05, HR-13, HR-14, HR-25, owner decision C): future
// debits in the balance, backdated termination, leave on the timesheets,
// accrual keys and expiry corrections, rostered staff's leave days.
class ReviewLeaveTest extends TestCase
{
    use BuildsHr, RefreshTenantDatabase;

    private Employee $worker;

    private User $workerUser;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpHr();
        $this->assertSame(0, Artisan::call('country-packs:holidays', ['code' => 'KE']));
        app(BusinessCalendar::class)->forgetHolidays();
        $this->workerUser = $this->userWith('employee_self_service', Scope::company($this->acme->id));
        $this->worker = $this->employee(['first_name' => 'Worker']);
        $this->inTenant(fn () => Employee::query()->whereKey($this->worker->id)->update(['user_id' => $this->workerUser->id]));
    }

    public function test_a_balance_that_may_not_go_negative_counts_leave_already_taken_later(): void
    {
        // 5 days a year from 1 January, hired 5 January: 4.95 for 2026.
        $type = $this->type(['code' => 'AL', 'accrual' => 'annual', 'entitlement_days' => '5', 'accrues_from' => '2026-01-01']);
        $this->inTenant(fn () => app(Accruals::class)->run(CarbonImmutable::parse('2026-10-14')));
        $this->assertSame('4.95', $this->balance($type, '2026-12-31'));

        // Four days in December, approved: 0.95 left, so two days in November are refused.
        $this->approve($this->request($type, '2026-12-07', '2026-12-10')->assertCreated()->json('data.id'));
        $this->request($type, '2026-11-02', '2026-11-03')->assertUnprocessable()->assertJsonPath('code', 'leave_balance_short');
        $this->assertSame('0.95', $this->inTenant(fn () => app(LeaveLedger::class)->available($this->worker->id, $type->id, CarbonImmutable::parse('2026-11-03'))));
    }

    public function test_a_backdated_termination_cancels_later_leave_trims_leave_spanning_it_and_keeps_earlier_requests(): void
    {
        $type = $this->type(['code' => 'AL', 'accrual' => 'annual', 'entitlement_days' => '30', 'accrues_from' => '2026-01-01']);
        $this->inTenant(fn () => app(Accruals::class)->run(CarbonImmutable::parse('2026-10-14')));

        // Approved leave that already ended (5 to 9 October), approved leave spanning
        // the termination date (19 to 23 October), and a pending request wholly before it.
        $past = $this->request($type, '2026-10-05', '2026-10-09')->assertCreated()->json('data.id');
        $spanning = $this->request($type, '2026-10-19', '2026-10-23')->assertCreated()->json('data.id');
        $this->approve($past);
        $this->approve($spanning);
        $early = $this->request($type, '2026-09-28', '2026-09-29')->assertCreated()->json('data.id');

        // Terminated on 21 October, entered later (backdated).
        $this->postJson("/api/v1/hr/employees/{$this->worker->id}/terminate", ['termination_date' => '2026-10-21', 'reason' => 'resignation'], $this->headersFor($this->hrOfficer))->assertOk();

        $requests = $this->inTenant(fn () => LeaveRequest::query()->whereKey([$past, $spanning, $early])->get()->keyBy('id'));
        $this->assertSame('approved', $requests[$past]->status);
        $this->assertSame('pending', $requests[$early]->status);
        // 19 Oct (Mon), 20 Oct is a holiday, 21 Oct kept; 22 and 23 posted back.
        $this->assertSame(['approved', '2026-10-21', '2.00'], [$requests[$spanning]->status, $requests[$spanning]->end_date->toDateString(), (string) $requests[$spanning]->days]);
        $this->assertSame('2.00', $this->inTenant(fn () => (string) LeaveEntry::query()->where('key', "trim:{$spanning}")->value('days')));

        // A former employee is no longer "the employee" of their requests.
        $this->postJson("/api/v1/hr/leave-requests/{$early}/cancel", [], $this->headersFor($this->workerUser))->assertNotFound();
    }

    public function test_approved_leave_turns_a_planned_shift_into_a_leave_day_on_the_timesheet(): void
    {
        $type = $this->type(['code' => 'UL', 'accrual' => 'none']);
        $shift = $this->postJson('/api/v1/hr/shifts', ['company_id' => $this->acme->id, 'code' => 'D', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '16:00'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/hr/roster-shifts', ['employee_id' => $this->worker->id, 'date' => '2026-10-12', 'shift_id' => $shift], $this->headersFor($this->hrOfficer))->assertCreated();
        $this->assertSame('absent', $this->inTenant(fn () => AttendanceDay::query()->sole()->status));

        $this->approve($this->request($type, '2026-10-12', '2026-10-12')->assertCreated()->json('data.id'));

        $this->assertSame('leave', $this->inTenant(fn () => AttendanceDay::query()->sole()->status));
    }

    public function test_switching_accrual_modes_never_grants_a_year_twice_and_late_december_leave_corrects_the_expiry(): void
    {
        $type = $this->type(['code' => 'AL', 'accrual' => 'monthly', 'entitlement_days' => '12', 'accrues_from' => '2026-01-01', 'carry_over_max_days' => '2']);
        $accruals = app(Accruals::class);
        $this->inTenant(fn () => $accruals->run(CarbonImmutable::parse('2026-10-14')));
        $this->assertSame('8.87', $this->balance($type, '2026-12-31'));

        // The company switches to annual mid-year: 2026 is not granted again.
        $this->patchJson("/api/v1/hr/leave-types/{$type->id}", ['accrual' => 'annual'], $this->headersFor($this->hrOfficer))->assertOk();
        $this->inTenant(fn () => $accruals->run(CarbonImmutable::parse('2026-10-14')));
        $this->assertSame('8.87', $this->balance($type, '2026-12-31'));

        // New year: 6.87 above the cap of 2 expires.
        $this->inTenant(fn () => $accruals->run(CarbonImmutable::parse('2027-01-02')));
        $this->assertSame('2.00', $this->balance($type, '2027-01-01', withoutAccrual: true));

        // Leave taken on 28 December, approved in January: the expiry is corrected on the next run.
        $this->inTenant(fn () => app(LeaveLedger::class)->post($this->worker->id, $type->id, 'taken', '-3', '2026-12-28', 'late-december'));
        $this->inTenant(fn () => $accruals->run(CarbonImmutable::parse('2027-01-03')));
        $this->assertSame('2.00', $this->balance($type, '2027-01-01', withoutAccrual: true));
        $this->assertSame(['-6.87', '3.00'], $this->inTenant(fn () => LeaveEntry::query()->where('employee_id', $this->worker->id)->where('kind', 'expiry')->orderBy('key')->pluck('days')->map(fn ($d) => (string) $d)->all()));

        // No run ahead of today.
        $this->postJson('/api/v1/hr/leave-accruals/run', ['company_id' => $this->acme->id, 'as_of' => '2027-06-01'], $this->headersFor($this->hrOfficer))->assertUnprocessable();
    }

    public function test_rostered_staff_count_only_the_days_they_are_rostered_to_work(): void
    {
        $type = $this->type(['code' => 'UL', 'accrual' => 'none']);
        $day = $this->postJson('/api/v1/hr/shifts', ['company_id' => $this->acme->id, 'code' => 'D', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '16:00'], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        // Works Saturday and Sunday, rests Monday to Friday (anchor Saturday 7 November 2026).
        $rotation = $this->postJson('/api/v1/hr/rotations', ['company_id' => $this->acme->id, 'name' => 'Weekends', 'days' => [$day, $day, null, null, null, null, null]], $this->headersFor($this->hrOfficer))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/hr/rosters', ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Weekend team', 'rotation_id' => $rotation, 'anchor_date' => '2026-11-07',
            'members' => [['employee_id' => $this->worker->id]]], $this->headersFor($this->hrOfficer))->assertCreated();

        // 9 to 15 November (Monday to Sunday): only Saturday 14 and Sunday 15 are rostered.
        $request = $this->request($type, '2026-11-09', '2026-11-15')->assertCreated()->json('data');
        $this->assertSame(['2026-11-14', '2026-11-15'], array_column($request['dates'], 'date'));

        // An office employee (no roster) counts the company's working days.
        $office = $this->employee(['first_name' => 'Office']);
        $this->assertSame('5.00', $this->postJson('/api/v1/hr/leave-requests', ['employee_id' => $office->id, 'leave_type_id' => $type->id, 'start_date' => '2026-11-09', 'end_date' => '2026-11-15'], $this->headersFor($this->hrOfficer))
            ->assertCreated()->json('data.days'));
    }

    /** @param array<string, mixed> $data */
    private function type(array $data): LeaveType
    {
        $id = $this->postJson('/api/v1/hr/leave-types', ['company_id' => $this->acme->id, 'name' => $data['code'], 'count_basis' => 'working_days', ...$data], $this->headersFor($this->hrOfficer))
            ->assertCreated()->json('data.id');

        return $this->inTenant(fn () => LeaveType::query()->findOrFail($id));
    }

    private function request(LeaveType $type, string $start, string $end): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/hr/leave-requests', ['employee_id' => $this->worker->id, 'leave_type_id' => $type->id, 'start_date' => $start, 'end_date' => $end], $this->headersFor($this->owner));
    }

    private function approve(string $requestId): void
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $requestId)->sole());
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($this->hrOfficer))->assertOk();
    }

    private function balance(LeaveType $type, string $asOf, bool $withoutAccrual = false): string
    {
        return $this->inTenant(function () use ($type, $asOf, $withoutAccrual) {
            $balance = app(LeaveLedger::class)->balance($this->worker->id, $type->id, CarbonImmutable::parse($asOf));

            if (! $withoutAccrual) {
                return $balance;
            }

            $grant = (string) LeaveEntry::query()->where('employee_id', $this->worker->id)->where('leave_type_id', $type->id)->where('kind', 'accrual')->where('effective_on', '>=', CarbonImmutable::parse($asOf)->startOfYear()->toDateString())->sum('days');

            return number_format((float) $balance - (float) $grant, 2, '.', '');
        });
    }
}
