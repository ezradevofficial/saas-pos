<?php

namespace Modules\Payroll\Employees;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
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
use Modules\Payroll\Models\LeaveDay;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Payroll\Models\SyncCursor;
use Modules\Payroll\Support\Upserts;

/**
 * PAY-05, PAY-11, PAY-12: Payroll's own copy of what it pays from, kept
 * from HR's events and HR's read contracts (`Employees`,
 * `EmploymentContracts`, `LeaveRecords`, `AttendanceRecords`), never from
 * HR's tables (architecture rule 1). Copying never touches the pay setup
 * Payroll owns (pay group, currency, method, dependants, hold).
 *
 * Stage 7C closing review (decisions 31 and 32): every write is a bulk
 * upsert (`insert … on conflict`), idempotent and safe when two syncs or
 * events race; a contract copy changes only for a version at least as
 * new as its own, so a late ContractChanged never overwrites a newer one;
 * a company's sync reads only what changed since its cursor per source
 * (`updated_at`, less an overlap for transactions that committed late).
 * Copy rows are not audited one by one (HR audits the source records); a
 * sync that copied something records one `payroll.employee.sync` entry.
 */
class EmployeeCopy
{
    /** Re-read this much before each cursor: a row saved in a transaction that committed after a later one is not missed. */
    public const OVERLAP_SECONDS = 300;

    public const SOURCES = ['employees', 'contracts', 'leave', 'attendance'];

    private const EMPLOYEE_COLUMNS = ['company_id', 'number', 'name', 'branch_id', 'location_id', 'department_id', 'position_id', 'user_id', 'hire_date', 'termination_date', 'status', 'synced_at'];

    private const CONTRACT_COLUMNS = ['employee_id', 'company_id', 'type', 'start_date', 'end_date', 'pay_basis', 'pay_minor', 'currency', 'hours_per_week', 'status', 'source_version'];

    public function __construct(
        private readonly Employees $employees,
        private readonly EmploymentContracts $contracts,
        private readonly LeaveRecords $leave,
        private readonly AttendanceRecords $attendance,
        private readonly Auditor $auditor,
    ) {}

    public function hired(EmployeeHired $event): void
    {
        $basics = $this->employees->find($event->employeeId) ?? [
            'id' => $event->employeeId, 'number' => $event->number, 'name' => $event->number, 'company_id' => $event->companyId,
            'branch_id' => $event->branchId, 'location_id' => $event->locationId, 'department_id' => $event->departmentId,
            'position_id' => $event->positionId, 'user_id' => null, 'hire_date' => $event->hireDate, 'termination_date' => null, 'status' => 'active',
        ];

        $this->upsertEmployees([$basics]);
    }

    public function terminated(EmployeeTerminated $event): void
    {
        $basics = $this->employees->find($event->employeeId);

        if ($basics !== null) {
            $this->upsertEmployees([$basics]);

            return;
        }

        PayrollEmployee::query()->where('employee_id', $event->employeeId)
            ->update(['termination_date' => $event->terminationDate, 'status' => PayrollEmployee::TERMINATED, 'synced_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);
    }

    public function contract(ContractChanged $event): void
    {
        $this->upsertContracts([[
            'id' => $event->contractId, 'employee_id' => $event->employeeId, 'company_id' => $event->companyId, 'type' => $event->type,
            'start_date' => $event->startDate, 'end_date' => $event->endDate, 'pay_basis' => $event->payBasis, 'pay_minor' => $event->payMinor,
            'currency' => $event->currency, 'hours_per_week' => $event->hoursPerWeek,
            'status' => $event->change === ContractChanged::ENDED ? 'ended' : 'active', 'version' => $event->version,
        ]]);

        // A contract may arrive before its employee is copied (queued listeners): copy the employee too.
        if (! PayrollEmployee::query()->where('employee_id', $event->employeeId)->exists() && ($basics = $this->employees->find($event->employeeId)) !== null) {
            $this->upsertEmployees([$basics]);
        }
    }

    /** PAY-11: unpaid leave days (paid leave changes nothing). */
    public function leaveApproved(LeaveApproved $event): void
    {
        if ($event->paid) {
            return;
        }

        $this->approveDays([[$event->requestId, $event->employeeId, $event->companyId, $event->dates]]);
    }

    /**
     * Review fix 8: order-safe. A cancelled day is a tombstone: a
     * LeaveApproved arriving after its LeaveCancelled (queued listeners,
     * retries) never brings it back; HR never approves a cancelled
     * request again (a new request has a new id).
     */
    public function leaveCancelled(LeaveCancelled $event): void
    {
        $this->cancelDays([[$event->requestId, $event->employeeId, $event->companyId, array_column($event->dates, 'date')]]);
    }

    /** PAY-12: each employee's minutes of a closed period (a period is closed once in HR). */
    public function attendanceClosed(AttendancePeriodClosed $event): void
    {
        $this->copyPeriods([['period_id' => $event->periodId, 'company_id' => $event->companyId, 'start_date' => $event->startDate, 'end_date' => $event->endDate, 'employees' => $event->employees]]);
    }

    /**
     * PAY-05: refresh the copy of a company's employees, contracts, unpaid
     * leave and closed attendance periods from HR's read contracts (before
     * a run is calculated, in a short transaction of its own; and on
     * demand, for a user with `hr.pay.view`), so Payroll switched on after
     * HR gets the history (review fix 8); only what changed since each
     * source's cursor is read (decision 32). Returns how many employees
     * were copied.
     */
    public function syncCompany(string $companyId, ?string $runId = null, ?User $user = null): int
    {
        if ($runId === null && $user === null) {
            throw new InvalidArgumentException('A payroll run or a user reads the contracts (review fix 9).');
        }

        $cursors = SyncCursor::query()->where('company_id', $companyId)->pluck('cursor', 'source')->all();
        $since = fn (string $source) => isset($cursors[$source]) ? CarbonImmutable::parse($cursors[$source])->subSeconds(self::OVERLAP_SECONDS) : null;
        $seen = [];

        $basics = $this->employees->ofCompany($companyId, since: $since('employees'));
        $this->upsertEmployees($basics);
        $seen['employees'] = $basics;

        $contracts = $runId !== null
            ? $this->contracts->forPayrollRun($companyId, $runId, since: $since('contracts'))
            : $this->contracts->forUser($companyId, $user, since: $since('contracts'));
        $this->upsertContracts($contracts);
        $seen['contracts'] = $contracts;

        $requests = $this->leave->unpaidOfCompany($companyId, $since('leave'));
        $this->copyLeave($requests);
        $seen['leave'] = $requests;

        $periods = $this->attendance->closedOfCompany($companyId, $since('attendance'));
        $this->copyPeriods($periods);
        $seen['attendance'] = $periods;

        $this->moveCursors($companyId, $seen);

        if ($basics !== [] || $contracts !== []) {
            $this->auditor->record('payroll.employee.sync', Company::query()->find($companyId), null, [
                'employees' => count($basics), 'contracts' => count($contracts), 'leave_requests' => count($requests), 'attendance_periods' => count($periods),
                'context' => $runId !== null ? 'payroll_run' : 'user', 'run_id' => $runId,
            ]);
        }

        return count($basics);
    }

    /** @param array<string, list<array<string, mixed>>> $seen rows read per source, each with HR's `updated_at` */
    private function moveCursors(string $companyId, array $seen): void
    {
        $rows = [];

        foreach ($seen as $source => $read) {
            $latest = null;

            foreach ($read as $row) {
                $at = $row['updated_at'] ?? null;

                if ($at !== null && ($latest === null || $at > $latest)) {
                    $latest = $at;
                }
            }

            if ($latest !== null) {
                $rows[] = ['company_id' => $companyId, 'source' => $source, 'cursor' => CarbonImmutable::parse($latest)->toIso8601String()];
            }
        }

        Upserts::run('payroll_sync_cursors', $rows, ['company_id', 'source'], ['cursor'], 'excluded.cursor > t.cursor');
    }

    /** @param list<array<string, mixed>> $basics HR's `Employees` shape */
    private function upsertEmployees(array $basics): void
    {
        $now = CarbonImmutable::now()->toIso8601String();

        Upserts::run('payroll_employees', array_map(fn (array $b) => [
            'employee_id' => $b['id'],
            'company_id' => $b['company_id'],
            'number' => $b['number'],
            'name' => mb_substr((string) $b['name'], 0, 320),
            'branch_id' => $b['branch_id'],
            'location_id' => $b['location_id'],
            'department_id' => $b['department_id'],
            'position_id' => $b['position_id'],
            'user_id' => $b['user_id'],
            'hire_date' => $b['hire_date'],
            'termination_date' => $b['termination_date'],
            'status' => $b['status'] === 'terminated' ? PayrollEmployee::TERMINATED : PayrollEmployee::ACTIVE,
            'synced_at' => $now,
        ], $basics), ['employee_id'], self::EMPLOYEE_COLUMNS,
            // Unchanged rows are left alone (no write, no new `updated_at`).
            '('.implode(', ', array_map(fn ($c) => "t.{$c}", array_diff(self::EMPLOYEE_COLUMNS, ['synced_at']))).') is distinct from ('.implode(', ', array_map(fn ($c) => "excluded.{$c}", array_diff(self::EMPLOYEE_COLUMNS, ['synced_at']))).')');
    }

    /**
     * Decision 31: a contract changes only for a version at least as new as
     * the one copied (HR's version is one more on every save).
     *
     * @param  list<array<string, mixed>>  $contracts
     */
    private function upsertContracts(array $contracts): void
    {
        Upserts::run('payroll_contracts', array_map(fn (array $c) => [
            'contract_id' => $c['id'],
            'employee_id' => $c['employee_id'],
            'company_id' => $c['company_id'],
            'type' => $c['type'],
            'start_date' => $c['start_date'],
            'end_date' => $c['end_date'],
            'pay_basis' => $c['pay_basis'],
            'pay_minor' => (string) $c['pay_minor'],
            'currency' => $c['currency'],
            'hours_per_week' => $c['hours_per_week'],
            'status' => $c['status'],
            'source_version' => (int) ($c['version'] ?? 0),
        ], $contracts), ['contract_id'], self::CONTRACT_COLUMNS, 'excluded.source_version >= t.source_version');
    }

    /** @param list<array{request_id: string, employee_id: string, company_id: string, status: string, dates: list<array{date: string, fraction: string}>}> $requests */
    private function copyLeave(array $requests): void
    {
        $approved = array_values(array_filter($requests, fn (array $r) => $r['status'] === 'approved'));
        $this->approveDays(array_map(fn (array $r) => [$r['request_id'], $r['employee_id'], $r['company_id'], $r['dates']], $approved));

        // A request trimmed in HR (a backdated termination) keeps only its current days; a cancelled one none.
        $kept = [];

        foreach ($requests as $r) {
            $kept[$r['request_id']] = $r['status'] === 'approved' ? array_column($r['dates'], 'date') : [];
        }

        $gone = [];

        foreach (LeaveDay::query()->whereIn('request_id', array_keys($kept))->whereNull('cancelled_at')->get(['request_id', 'employee_id', 'company_id', 'date']) as $day) {
            if (! in_array($day->date->toDateString(), $kept[$day->request_id], true)) {
                $gone[$day->request_id] ??= [$day->request_id, $day->employee_id, $day->company_id, []];
                $gone[$day->request_id][3][] = $day->date->toDateString();
            }
        }

        $cancelled = array_filter($requests, fn (array $r) => $r['status'] !== 'approved');
        $this->cancelDays([
            ...array_values($gone),
            ...array_map(fn (array $r) => [$r['request_id'], $r['employee_id'], $r['company_id'], array_column($r['dates'], 'date')], array_values($cancelled)),
        ]);
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: list<array{date: string, fraction: string}>}> $requests [request, employee, company, dates] */
    private function approveDays(array $requests): void
    {
        $rows = [];

        foreach ($requests as [$requestId, $employeeId, $companyId, $dates]) {
            foreach ($dates as $day) {
                $rows[] = ['request_id' => $requestId, 'date' => $day['date'], 'employee_id' => $employeeId, 'company_id' => $companyId, 'fraction' => (string) $day['fraction'], 'cancelled_at' => null];
            }
        }

        // A tombstone (a cancelled day) is never brought back.
        Upserts::run('payroll_leave_days', $rows, ['request_id', 'date'], ['employee_id', 'company_id', 'fraction'], 't.cancelled_at is null and t.fraction is distinct from excluded.fraction');
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: list<string>}> $requests [request, employee, company, dates] */
    private function cancelDays(array $requests): void
    {
        $now = CarbonImmutable::now()->toIso8601String();
        $rows = [];

        foreach ($requests as [$requestId, $employeeId, $companyId, $dates]) {
            foreach ($dates as $date) {
                $rows[] = ['request_id' => $requestId, 'date' => $date, 'employee_id' => $employeeId, 'company_id' => $companyId, 'fraction' => '0', 'cancelled_at' => $now];
            }
        }

        Upserts::run('payroll_leave_days', $rows, ['request_id', 'date'], ['cancelled_at'], 't.cancelled_at is null');
    }

    /** @param list<array{period_id: string, company_id: string, start_date: string, end_date: string, employees: list<array<string, mixed>>}> $periods */
    private function copyPeriods(array $periods): void
    {
        $rows = [];

        foreach ($periods as $period) {
            foreach ($period['employees'] as $row) {
                $rows[] = [
                    'period_id' => $period['period_id'],
                    'employee_id' => $row['employee_id'],
                    'company_id' => $period['company_id'],
                    'start_date' => $period['start_date'],
                    'end_date' => $period['end_date'],
                    'worked_minutes' => (int) $row['worked_minutes'],
                    'regular_minutes' => (int) $row['regular_minutes'],
                    'overtime_minutes' => (int) $row['overtime_minutes'],
                    'night_minutes' => (int) $row['night_minutes'],
                    'holiday_minutes' => (int) $row['holiday_minutes'],
                    'rest_day_minutes' => (int) $row['rest_day_minutes'],
                    'days_present' => (int) $row['days_present'],
                ];
            }
        }

        // A closed period never changes in HR: what is copied stays.
        Upserts::run('payroll_attendance', $rows, ['period_id', 'employee_id'], null);
    }
}
