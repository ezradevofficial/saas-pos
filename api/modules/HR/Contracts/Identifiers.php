<?php

namespace Modules\HR\Contracts;

use Modules\HR\Models\Employee;
use Modules\HR\Models\EmployeeIdentifier;
use Modules\HR\Support\Sensitive;

/**
 * HR-33: an employee's identifiers (type => value), decrypted, for
 * Payroll's statutory returns: `forUser()` (hr.identity.view) or
 * `forPayrollRun()`.
 */
final class Identifiers extends SensitiveReads
{
    protected function group(): string
    {
        return Sensitive::IDENTITY;
    }

    /** @return array<string, string> */
    protected function read(Employee $employee): array
    {
        return EmployeeIdentifier::query()->where('employee_id', $employee->id)->orderBy('type')->get()
            ->mapWithKeys(fn (EmployeeIdentifier $i) => [$i->type => $i->value])->all();
    }
}
