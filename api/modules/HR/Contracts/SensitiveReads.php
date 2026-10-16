<?php

namespace Modules\HR\Contracts;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Sensitive;

/**
 * HR-33: what PayAccounts and Identifiers share: a read for a person needs
 * the group's view permission at the employee's place (and field rules
 * that do not hide it); a read inside a payroll run is the system's, named
 * by its run. Every read is audited (`hr.employee.sensitive_read`).
 */
abstract class SensitiveReads
{
    public function __construct(
        protected readonly Sensitive $sensitive,
        protected readonly Auditor $auditor,
    ) {}

    abstract protected function group(): string;

    /** @return list<array<string, mixed>>|array<string, string> */
    abstract protected function read(Employee $employee): array;

    /** For $actor, who must see the group in full at the employee's place (403 otherwise). */
    public function forUser(string $employeeId, User $actor): array
    {
        $employee = Employee::query()->findOrFail($employeeId);

        if (! $this->sensitive->sees($actor, $employee, $this->group())) {
            throw new ApiException(403, 'forbidden', __('hr.errors.place_forbidden'));
        }

        $this->auditor->record('hr.employee.sensitive_read', $employee, null, ['groups' => [$this->group()], 'context' => 'user']);

        return $this->read($employee);
    }

    /**
     * Stage 7C closing review (payroll.md decision 38): many employees for
     * $actor in one read. Those whose group $actor sees in full at their
     * place are read and audited in one entry naming them; the others are
     * returned as refused (left out, never a 403 for the whole batch).
     *
     * @param  list<string>  $employeeIds
     * @return array{read: array<string, array>, refused: list<string>}
     */
    public function manyForUser(array $employeeIds, User $actor): array
    {
        $seen = [];
        $refused = [];

        foreach (Employee::query()->whereIn('id', array_values(array_unique($employeeIds)))->orderBy('number')->get() as $employee) {
            if ($this->sensitive->sees($actor, $employee, $this->group())) {
                $seen[$employee->id] = $employee;
            } else {
                $refused[] = $employee->id;
            }
        }

        if ($seen !== []) {
            $this->auditor->record('hr.employee.sensitive_read', null, null, ['groups' => [$this->group()], 'context' => 'user', 'employees' => array_keys($seen)]);
        }

        return ['read' => $this->readMany(array_values($seen)), 'refused' => $refused];
    }

    /**
     * @param  list<Employee>  $employees
     * @return array<string, array> by employee id
     */
    protected function readMany(array $employees): array
    {
        $read = [];

        foreach ($employees as $employee) {
            $read[$employee->id] = $this->read($employee);
        }

        return $read;
    }

    /** Inside payroll run $runId (the system acting for an approved run), audited with the run. */
    public function forPayrollRun(string $employeeId, string $runId): array
    {
        $employee = Employee::query()->findOrFail($employeeId);
        $this->auditor->record('hr.employee.sensitive_read', $employee, null, ['groups' => [$this->group()], 'context' => 'payroll_run', 'run_id' => $runId]);

        return $this->read($employee);
    }
}
