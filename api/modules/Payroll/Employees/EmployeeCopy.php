<?php

namespace Modules\Payroll\Employees;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\HR\Contracts\AttendanceRecords;
use Modules\HR\Contracts\Employees;
use Modules\HR\Contracts\EmploymentContracts;
use Modules\HR\Contracts\LeaveRecords;
use Modules\HR\Events\AttendancePeriodClosed;
use Modules\HR\Events\ContractChanged;
use Modules\HR\Events\EmployeeHired;
use Modules\HR\Events\EmployeeTerminated;
use Modules\HR\Events\LeaveApproved;
use Modules\HR\Events\LeaveCancelled;
use Modules\Payroll\Models\Attendance;
use Modules\Payroll\Models\LeaveDay;
use Modules\Payroll\Models\PayrollContract;
use Modules\Payroll\Models\PayrollEmployee;

/**
 * PAY-05, PAY-11, PAY-12: Payroll's own copy of what it pays from, kept
 * from HR's events and HR's read contracts (`Employees`,
 * `EmploymentContracts`), never from HR's tables (architecture rule 1).
 * Copying never touches the pay setup Payroll owns (pay group, currency,
 * method, dependants, hold). Every write is idempotent: a replayed or
 * repeated event changes nothing more.
 */
class EmployeeCopy
{
    public function __construct(
        private readonly Employees $employees,
        private readonly EmploymentContracts $contracts,
        private readonly LeaveRecords $leave,
        private readonly AttendanceRecords $attendance,
    ) {}

    public function hired(EmployeeHired $event): void
    {
        $basics = $this->employees->find($event->employeeId) ?? [
            'id' => $event->employeeId, 'number' => $event->number, 'name' => $event->number, 'company_id' => $event->companyId,
            'branch_id' => $event->branchId, 'location_id' => $event->locationId, 'department_id' => $event->departmentId,
            'position_id' => $event->positionId, 'user_id' => null, 'hire_date' => $event->hireDate, 'termination_date' => null, 'status' => 'active',
        ];

        $this->upsert($basics);
    }

    public function terminated(EmployeeTerminated $event): void
    {
        $basics = $this->employees->find($event->employeeId);

        if ($basics !== null) {
            $this->upsert($basics);

            return;
        }

        $copy = PayrollEmployee::query()->where('employee_id', $event->employeeId)->first();
        $copy?->fill(['termination_date' => $event->terminationDate, 'status' => PayrollEmployee::TERMINATED, 'synced_at' => now()])->save();
    }

    public function contract(ContractChanged $event): void
    {
        $this->upsertContract([
            'id' => $event->contractId, 'employee_id' => $event->employeeId, 'company_id' => $event->companyId, 'type' => $event->type,
            'start_date' => $event->startDate, 'end_date' => $event->endDate, 'pay_basis' => $event->payBasis, 'pay_minor' => $event->payMinor,
            'currency' => $event->currency, 'hours_per_week' => $event->hoursPerWeek,
            'status' => $event->change === ContractChanged::ENDED ? 'ended' : 'active',
        ]);

        // A contract may arrive before its employee is copied (queued listeners): copy the employee too.
        if (! PayrollEmployee::query()->where('employee_id', $event->employeeId)->exists() && ($basics = $this->employees->find($event->employeeId)) !== null) {
            $this->upsert($basics);
        }
    }

    /** PAY-11: unpaid leave days (paid leave changes nothing). */
    public function leaveApproved(LeaveApproved $event): void
    {
        if ($event->paid) {
            return;
        }

        $this->approveDays($event->requestId, $event->employeeId, $event->companyId, $event->dates);
    }

    /**
     * Review fix 8: order-safe. A cancelled day is a tombstone: a
     * LeaveApproved arriving after its LeaveCancelled (queued listeners,
     * retries) never brings it back; HR never approves a cancelled
     * request again (a new request has a new id).
     */
    public function leaveCancelled(LeaveCancelled $event): void
    {
        $this->cancelDays($event->requestId, $event->employeeId, $event->companyId, array_column($event->dates, 'date'));
    }

    /** @param list<array{date: string, fraction: string}> $dates */
    private function approveDays(string $requestId, string $employeeId, string $companyId, array $dates): void
    {
        foreach ($dates as $day) {
            $row = LeaveDay::query()->firstOrNew(['request_id' => $requestId, 'date' => $day['date']]);

            if ($row->exists && $row->cancelled_at !== null) {
                continue;
            }

            $row->fill(['employee_id' => $employeeId, 'company_id' => $companyId, 'fraction' => $day['fraction']])->save();
        }
    }

    /** @param list<string> $dates */
    private function cancelDays(string $requestId, string $employeeId, string $companyId, array $dates): void
    {
        foreach ($dates as $date) {
            $row = LeaveDay::query()->firstOrNew(['request_id' => $requestId, 'date' => $date], ['fraction' => '0']);

            if ($row->cancelled_at === null) {
                $row->fill(['employee_id' => $employeeId, 'company_id' => $companyId, 'cancelled_at' => now()])->save();
            }
        }
    }

    /** PAY-12: each employee's minutes of a closed period (a period is closed once in HR). */
    public function attendanceClosed(AttendancePeriodClosed $event): void
    {
        $this->copyPeriod($event->periodId, $event->companyId, $event->startDate, $event->endDate, $event->employees);
    }

    /** @param list<array<string, mixed>> $employees */
    private function copyPeriod(string $periodId, string $companyId, string $startDate, string $endDate, array $employees): void
    {
        foreach ($employees as $row) {
            Attendance::query()->firstOrCreate(['period_id' => $periodId, 'employee_id' => $row['employee_id']], [
                'company_id' => $companyId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'worked_minutes' => (int) $row['worked_minutes'],
                'regular_minutes' => (int) $row['regular_minutes'],
                'overtime_minutes' => (int) $row['overtime_minutes'],
                'night_minutes' => (int) $row['night_minutes'],
                'holiday_minutes' => (int) $row['holiday_minutes'],
                'rest_day_minutes' => (int) $row['rest_day_minutes'],
                'days_present' => (int) $row['days_present'],
            ]);
        }
    }

    /**
     * PAY-05: refresh the copy of a company's employees, contracts, unpaid
     * leave and closed attendance periods from HR's read contracts (before
     * a run is calculated, inside it; and on demand, for a user with
     * `hr.pay.view`), so Payroll switched on after HR gets the history
     * (review fix 8); returns how many employees were copied.
     */
    public function syncCompany(string $companyId, ?string $runId = null, ?User $user = null): int
    {
        if ($runId === null && $user === null) {
            throw new InvalidArgumentException('A payroll run or a user reads the contracts (review fix 9).');
        }

        $basics = $this->employees->ofCompany($companyId);

        foreach ($basics as $employee) {
            $this->upsert($employee);
        }

        $contracts = $runId !== null ? $this->contracts->forPayrollRun($companyId, $runId) : $this->contracts->forUser($companyId, $user);

        foreach ($contracts as $contract) {
            $this->upsertContract($contract);
        }

        foreach ($this->leave->unpaidOfCompany($companyId) as $request) {
            $request['status'] === 'approved'
                ? $this->approveDays($request['request_id'], $request['employee_id'], $request['company_id'], $request['dates'])
                : $this->cancelDays($request['request_id'], $request['employee_id'], $request['company_id'], array_column($request['dates'], 'date'));

            // A request trimmed in HR (a backdated termination) keeps only its current days.
            if ($request['status'] === 'approved') {
                $kept = array_column($request['dates'], 'date');
                $gone = LeaveDay::query()->where('request_id', $request['request_id'])->whereNull('cancelled_at')->get()
                    ->filter(fn (LeaveDay $d) => ! in_array($d->date->toDateString(), $kept, true))->map(fn (LeaveDay $d) => $d->date->toDateString())->values()->all();
                $this->cancelDays($request['request_id'], $request['employee_id'], $request['company_id'], $gone);
            }
        }

        foreach ($this->attendance->closedOfCompany($companyId) as $period) {
            $this->copyPeriod($period['period_id'], $period['company_id'], $period['start_date'], $period['end_date'], $period['employees']);
        }

        return count($basics);
    }

    /** @param array<string, mixed> $basics HR's `Employees` shape */
    private function upsert(array $basics): PayrollEmployee
    {
        $copy = PayrollEmployee::query()->firstOrNew(['employee_id' => $basics['id']]);
        $copy->fill([
            'company_id' => $basics['company_id'],
            'number' => $basics['number'],
            'name' => mb_substr((string) $basics['name'], 0, 320),
            'branch_id' => $basics['branch_id'],
            'location_id' => $basics['location_id'],
            'department_id' => $basics['department_id'],
            'position_id' => $basics['position_id'],
            'user_id' => $basics['user_id'],
            'hire_date' => $basics['hire_date'],
            'termination_date' => $basics['termination_date'],
            'status' => $basics['status'] === 'terminated' ? PayrollEmployee::TERMINATED : PayrollEmployee::ACTIVE,
        ]);

        if ($copy->isDirty() || ! $copy->exists) {
            $copy->synced_at = CarbonImmutable::now();
            $copy->save();
        }

        return $copy;
    }

    /** @param array<string, mixed> $contract */
    private function upsertContract(array $contract): void
    {
        $copy = PayrollContract::query()->firstOrNew(['contract_id' => $contract['id']]);
        $copy->fill([
            'employee_id' => $contract['employee_id'],
            'company_id' => $contract['company_id'],
            'type' => $contract['type'],
            'start_date' => $contract['start_date'],
            'end_date' => $contract['end_date'],
            'pay_basis' => $contract['pay_basis'],
            'pay_minor' => (int) $contract['pay_minor'],
            'currency' => $contract['currency'],
            'hours_per_week' => $contract['hours_per_week'],
            'status' => $contract['status'],
        ]);

        if ($copy->isDirty() || ! $copy->exists) {
            $copy->save();
        }
    }
}
