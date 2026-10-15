<?php

namespace Modules\HR\Contracts;

use Modules\HR\Models\Employee;

/**
 * HR-33 (stage 7C, Payroll): employees' non-sensitive basics for other
 * modules (number, name, company, place, department, position, user link,
 * hire and termination dates, status), so Payroll keeps its copy fresh and
 * finds a signed-in user's employee without reading HR's tables
 * (architecture rule 1). No identifier, date of birth, pay, account or
 * medical data: those stay behind PayAccounts, Identifiers and
 * EmploymentContracts.
 */
final class Employees
{
    /** @return list<array<string, mixed>> the employees of $companyId (or only $ids), as basics() */
    public function ofCompany(string $companyId, ?array $ids = null): array
    {
        return Employee::query()->where('company_id', $companyId)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('number')->get()
            ->map(fn (Employee $e) => self::basics($e))->all();
    }

    /** @return array<string, mixed>|null */
    public function find(string $employeeId): ?array
    {
        $employee = Employee::query()->find($employeeId);

        return $employee === null ? null : self::basics($employee);
    }

    /** @return list<string> the ids of the employees linked to user $userId (HR-31: at most one) */
    public function linkedTo(string $userId): array
    {
        return Employee::query()->where('user_id', $userId)->pluck('id')->all();
    }

    /** @return array<string, mixed> */
    private static function basics(Employee $e): array
    {
        return [
            'id' => $e->id,
            'number' => $e->number,
            'name' => $e->displayName(),
            'company_id' => $e->company_id,
            'branch_id' => $e->branch_id,
            'location_id' => $e->location_id,
            'department_id' => $e->department_id,
            'position_id' => $e->position_id,
            'user_id' => $e->user_id,
            'hire_date' => $e->hire_date->toDateString(),
            'termination_date' => $e->termination_date?->toDateString(),
            'status' => $e->status,
        ];
    }
}
