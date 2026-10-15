<?php

namespace Modules\HR\Employees;

use App\Core\Audit\Auditor;
use App\Core\CustomFields\CustomFieldWriter;
use App\Core\Identity\Models\User;
use App\Core\Limits\Units;
use App\Core\Limits\UsageLimits;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Modules\HR\Events\EmployeeHired;
use Modules\HR\Events\EmployeeTerminated;
use Modules\HR\HrServiceProvider;
use Modules\HR\Leave\LeaveRequests;
use Modules\HR\Leave\LeaveTypes;
use Modules\HR\Models\Contract;
use Modules\HR\Models\Employee;
use Modules\HR\Models\EmployeePlace;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\Position;
use Modules\HR\Models\RosterShift;
use Modules\HR\Models\ShiftSwap;
use Modules\HR\Records\Checklists;
use Modules\HR\Support\Errors;
use Modules\HR\Support\Numbers;

/**
 * HR-02, HR-05: employees.
 *
 * - hire(): a checked place, department, position and line manager;
 *   numbered and saved in one transaction behind the employee usage guard
 *   (HR-32); the company's statutory leave types are copied on its first
 *   hire (HR-12); the default onboarding checklist starts (HR-28).
 * - update(): the same checks; sensitive groups are written by their own
 *   services, medical notes and date of birth here (audited by name).
 * - terminate(), rehire(): the lifecycle, with its events (HR-33).
 */
class Employees
{
    /** Columns update() writes (others belong to their own services). */
    public const EDITABLE = [
        'branch_id', 'location_id', 'department_id', 'position_id', 'reports_to_id', 'user_id', 'first_name', 'middle_name', 'last_name',
        'gender', 'date_of_birth', 'work_email', 'work_phone', 'personal_email', 'personal_phone', 'address', 'emergency_contacts',
        'medical_notes', 'clock_methods', 'biometric_ref', 'hire_date',
    ];

    public function __construct(
        private readonly Numbers $numbers,
        private readonly UsageLimits $limits,
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
        private readonly LeaveTypes $leaveTypes,
        private readonly Checklists $checklists,
        private readonly CustomFieldWriter $customFields,
    ) {}

    /** @param array<string, mixed> $data validated */
    public function hire(array $data, User $by): Employee
    {
        $company = Company::query()->whereKey($data['company_id'])->whereNull('archived_at')->firstOrFail();
        $fields = $this->checked($company->id, $data, null);

        $employee = Errors::transaction(function () use ($company, $data, $fields, $by) {
            // HR-32, LIM-03: hiring adds an employee.
            $this->limits->ensureCanAdd(Units::EMPLOYEE);

            $number = isset($data['number']) && $data['number'] !== '' ? (string) $data['number'] : $this->numbers->next(Numbers::EMPLOYEE, $company->id, $fields['branch_id'] ?? null);
            $this->assertNumberFree($company->id, $number);

            $employee = new Employee([
                ...$fields,
                'number' => $number,
                'company_id' => $company->id,
                'status' => Employee::ACTIVE,
                'hire_date' => $data['hire_date'],
                'emergency_contacts' => $fields['emergency_contacts'] ?? [],
                'clock_methods' => $fields['clock_methods'] ?? [],
                'created_by' => $by->id,
            ]);
            // CF-01: custom field values.
            $this->customFields->fill(EmployeeEntity::KEY, $employee, $data['custom'] ?? null, creating: true);
            $employee->save();
            $this->customFields->saved(EmployeeEntity::KEY, $employee);
            $this->recordSensitive($employee, array_keys(array_intersect_key($fields, array_flip(Employee::SENSITIVE_COLUMNS))));
            $this->placeFrom($employee, $employee->hire_date->toDateString());

            if ($this->hrActive()) {
                // HR-12: a company's first hire brings its statutory leave types.
                $this->leaveTypes->applyPack($company);
                // HR-28: the default onboarding checklist.
                $this->checklists->startDefault($employee, 'onboarding', $by);
            }

            $this->hired($employee, false);

            return $employee;
        });

        return $employee->refresh();
    }

    /** @param array<string, mixed> $data validated (only the keys sent) */
    public function update(Employee $employee, array $data, User $by): Employee
    {
        $fields = $this->checked($employee->company_id, $data, $employee);

        return Errors::transaction(function () use ($employee, $fields, $data) {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $employee->fill($fields);

            if (array_key_exists('custom', $data)) {
                $this->customFields->fill(EmployeeEntity::KEY, $employee, $data['custom'], creating: false);
            }

            $sensitive = array_values(array_filter(Employee::SENSITIVE_COLUMNS, fn (string $column) => $employee->isDirty($column)));
            $moved = $employee->isDirty('branch_id') || $employee->isDirty('location_id');
            $employee->save();
            $this->customFields->saved(EmployeeEntity::KEY, $employee);
            $this->recordSensitive($employee, $sensitive);

            if ($moved) {
                $this->transfer($employee);
            }

            return $employee->refresh();
        });
    }

    /** @param array{termination_date: string, reason: string, note?: ?string} $data */
    public function terminate(Employee $employee, array $data, User $by): Employee
    {
        return Errors::transaction(function () use ($employee, $data, $by) {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if (! $employee->isActive()) {
                throw Errors::make(409, 'employee_terminated');
            }

            $date = CarbonImmutable::parse($data['termination_date']);

            if ($date->lessThan($employee->hire_date)) {
                throw Errors::make(422, 'termination_before_hire', field: 'termination_date');
            }

            $employee->fill([
                'status' => Employee::TERMINATED,
                'termination_date' => $date->toDateString(),
                'termination_reason' => $data['reason'],
                'termination_note' => $data['note'] ?? null,
            ])->save();
            $this->closePlace($employee, $date->toDateString());

            // HR-07: the contract in force ends on the termination date.
            foreach (Contract::query()->where('employee_id', $employee->id)->where('status', Contract::ACTIVE)->get() as $contract) {
                app(Contracts::class)->endOnTermination($contract, $date, $by);
            }

            if ($this->hrActive()) {
                // HR-05: leave after the date is cancelled (or trimmed).
                app(LeaveRequests::class)->cancelAfter($employee, $date, $by);
                $this->checklists->startDefault($employee, 'offboarding', $by);
            }

            EmployeeTerminated::dispatch($this->tenants->require(), $employee->id, $employee->number, $employee->company_id, $date->toDateString(), $data['reason']);

            return $employee->refresh();
        });
    }

    /** HR-05: a terminated employee back to active from $hireDate. */
    public function rehire(Employee $employee, string $hireDate, User $by): Employee
    {
        return Errors::transaction(function () use ($employee, $hireDate, $by) {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if ($employee->isActive()) {
                throw Errors::make(409, 'employee_active');
            }

            if (CarbonImmutable::parse($hireDate)->lessThanOrEqualTo($employee->termination_date)) {
                throw Errors::make(422, 'rehire_before_termination', field: 'hire_date');
            }

            // HR-32, LIM-03: rehiring adds an active employee.
            $this->limits->ensureCanAdd(Units::EMPLOYEE);

            $employee->fill([
                'status' => Employee::ACTIVE,
                'hire_date' => $hireDate,
                'termination_date' => null,
                'termination_reason' => null,
                'termination_note' => null,
            ])->save();
            $this->placeFrom($employee, $hireDate);

            if ($this->hrActive()) {
                $this->checklists->startDefault($employee, 'onboarding', $by);
            }

            $this->hired($employee, true);

            return $employee->refresh();
        });
    }

    /**
     * The checked columns of $data for an employee of $companyId: the place
     * (branch and location of the company), department, position and line
     * manager of the company, and a user not linked to another employee.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function checked(string $companyId, array $data, ?Employee $current): array
    {
        $fields = array_intersect_key($data, array_flip(self::EDITABLE));
        $branchId = array_key_exists('branch_id', $fields) ? $fields['branch_id'] : $current?->branch_id;
        $locationId = array_key_exists('location_id', $fields) ? $fields['location_id'] : $current?->location_id;

        if ($branchId !== null && ! Branch::query()->whereKey($branchId)->where('company_id', $companyId)->whereNull('archived_at')->exists()) {
            throw Errors::make(422, 'place_other_company', field: 'branch_id');
        }

        if ($locationId !== null) {
            $location = Location::query()->whereKey($locationId)->whereNull('archived_at')->first(['id', 'branch_id']);

            if ($location === null || ! Branch::query()->whereKey($location->branch_id)->where('company_id', $companyId)->exists()) {
                throw Errors::make(422, 'place_other_company', field: 'location_id');
            }

            // A location sets its branch.
            $fields['branch_id'] = $location->branch_id;
        }

        if (($fields['department_id'] ?? null) !== null && ! Department::query()->whereKey($fields['department_id'])->where('company_id', $companyId)->whereNull('archived_at')->exists()) {
            throw Errors::make(422, 'department_other_company', field: 'department_id');
        }

        if (($fields['position_id'] ?? null) !== null && ! Position::query()->whereKey($fields['position_id'])->where('company_id', $companyId)->whereNull('archived_at')->exists()) {
            throw Errors::make(422, 'position_other_company', field: 'position_id');
        }

        if (($fields['reports_to_id'] ?? null) !== null) {
            $this->assertManager($companyId, $fields['reports_to_id'], $current);
        }

        if (($fields['user_id'] ?? null) !== null) {
            $linked = Employee::query()->where('user_id', $fields['user_id'])->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))->exists();

            if ($linked) {
                throw Errors::make(422, 'user_linked', field: 'user_id');
            }
        }

        if (array_key_exists('clock_methods', $fields) && $fields['clock_methods'] !== null) {
            $fields['clock_methods'] = array_values(array_unique($fields['clock_methods']));
        }

        if (($fields['biometric_ref'] ?? null) !== null) {
            $taken = Employee::query()->where('company_id', $companyId)->where('biometric_ref', $fields['biometric_ref'])
                ->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))->exists();

            if ($taken) {
                throw Errors::make(422, 'biometric_ref_taken', field: 'biometric_ref');
            }
        }

        return $fields;
    }

    /** HR-02: the line manager is an active employee of the company and never the employee or below it. */
    private function assertManager(string $companyId, string $managerId, ?Employee $current): void
    {
        $manager = Employee::query()->whereKey($managerId)->first(['id', 'company_id', 'status', 'reports_to_id']);

        if ($manager === null || $manager->company_id !== $companyId || ! $manager->isActive()) {
            throw Errors::make(422, 'manager_other_company', field: 'reports_to_id');
        }

        if ($current === null) {
            return;
        }

        // Walk up from the manager: meeting the employee would make a cycle.
        $seen = [];
        $id = $manager->id;

        while ($id !== null && ! isset($seen[$id])) {
            if ($id === $current->id) {
                throw Errors::make(422, 'manager_cycle', field: 'reports_to_id');
            }

            $seen[$id] = true;
            $id = Employee::query()->whereKey($id)->value('reports_to_id');
        }
    }

    private function assertNumberFree(string $companyId, string $number): void
    {
        if (Employee::query()->where('company_id', $companyId)->whereRaw('lower(number) = lower(?)', [$number])->exists()) {
            throw Errors::make(422, 'number_taken', field: 'number');
        }
    }

    /** HR-11: sensitive columns are audited by name, never by value. @param list<string> $columns */
    private function recordSensitive(Employee $employee, array $columns): void
    {
        if ($columns !== []) {
            $this->auditor->record('hr.employee.sensitive_update', $employee, null, ['fields' => array_values($columns)]);
        }
    }

    /**
     * HR-02: a move to another branch or location takes the employee's
     * pending leave, future planned shifts and open swaps of those shifts
     * along (they are scoped by the employee's place), and starts a new
     * place from today (queued offline punches of the old place still
     * count, HR-23).
     */
    private function transfer(Employee $employee): void
    {
        $today = CarbonImmutable::now()->toDateString();
        $place = ['branch_id' => $employee->branch_id, 'location_id' => $employee->location_id];

        foreach (LeaveRequest::query()->where('employee_id', $employee->id)->where('status', LeaveRequest::PENDING)->get() as $request) {
            $request->fill($place)->save();
        }

        $shifts = RosterShift::query()->where('employee_id', $employee->id)->where('status', RosterShift::PLANNED)->where('date', '>=', $today)->get();

        foreach ($shifts as $shift) {
            $shift->fill($place)->save();
        }

        $swaps = ShiftSwap::query()->whereIn('roster_shift_id', $shifts->pluck('id')->all())
            ->whereIn('status', [ShiftSwap::AWAITING_PEER, ShiftSwap::PENDING])->get();

        foreach ($swaps as $swap) {
            $shift = $shifts->firstWhere('id', $swap->roster_shift_id);
            $swap->fill(['branch_id' => $shift->branch_id, 'location_id' => $shift->location_id, 'shift_version' => $shift->version])->save();
        }

        $this->placeFrom($employee, $today);
    }

    /** HR-23: the employee's place from $from (the open one before it ends the day before). */
    private function placeFrom(Employee $employee, string $from): void
    {
        $open = EmployeePlace::query()->where('employee_id', $employee->id)->whereNull('effective_to')->lockForUpdate()->first();

        if ($open !== null) {
            if ($open->effective_from->toDateString() >= $from) {
                $open->fill(['company_id' => $employee->company_id, 'branch_id' => $employee->branch_id, 'location_id' => $employee->location_id, 'effective_from' => $from])->save();

                return;
            }

            $open->fill(['effective_to' => CarbonImmutable::parse($from)->subDay()->toDateString()])->save();
        }

        EmployeePlace::create([
            'employee_id' => $employee->id, 'company_id' => $employee->company_id, 'branch_id' => $employee->branch_id,
            'location_id' => $employee->location_id, 'effective_from' => $from,
        ]);
    }

    private function closePlace(Employee $employee, string $on): void
    {
        $open = EmployeePlace::query()->where('employee_id', $employee->id)->whereNull('effective_to')->first();
        $open?->fill(['effective_to' => max($on, $open->effective_from->toDateString())])->save();
    }

    /** Owner decision B: with Payroll alone, HR's leave and checklists are off. */
    private function hrActive(): bool
    {
        return app(ModuleRegistry::class)->isActive(HrServiceProvider::MODULE);
    }

    private function hired(Employee $employee, bool $rehired): void
    {
        EmployeeHired::dispatch(
            $this->tenants->require(), $employee->id, $employee->number, $employee->company_id, $employee->branch_id, $employee->location_id,
            $employee->department_id, $employee->position_id, $employee->hire_date->toDateString(), $rehired,
        );
    }
}
