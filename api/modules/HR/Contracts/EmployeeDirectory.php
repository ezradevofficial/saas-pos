<?php

namespace Modules\HR\Contracts;

use Illuminate\Support\Str;
use Modules\HR\Models\Employee;

/**
 * A read contract for other modules (Fixed assets' custodians,
 * docs/modules/fixed-assets.md FA-04): who an employee is (id, number,
 * name, company, branch, status), read under the tenant's row-level
 * security, never anything of the sensitive groups (HR-09). Callers use it
 * only while HR is active for the tenant.
 */
final class EmployeeDirectory
{
    /** @return array{id: string, number: string, name: string, company_id: string, branch_id: ?string, status: string}|null */
    public function find(string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $employee = Employee::query()->find($id);

        return $employee === null ? null : self::row($employee);
    }

    /**
     * Active employees of $companyId whose name or number matches $search.
     *
     * @return list<array{id: string, number: string, name: string, company_id: string, branch_id: ?string, status: string}>
     */
    public function search(string $companyId, string $search = '', int $limit = 20): array
    {
        $query = Employee::query()->where('company_id', $companyId)->where('status', Employee::ACTIVE);
        $search = trim($search);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($q) => $q->where('number', 'ilike', $like)->orWhere('first_name', 'ilike', $like)->orWhere('last_name', 'ilike', $like));
        }

        return $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit($limit)->get()
            ->map(fn (Employee $employee) => self::row($employee))->values()->all();
    }

    /**
     * The names of $ids, by id (unknown ids left out).
     *
     * @param  list<?string>  $ids
     * @return array<string, string>
     */
    public function names(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), fn ($id) => is_string($id) && Str::isUuid($id)));

        return $ids === [] ? [] : Employee::query()->whereKey($ids)->get()
            ->mapWithKeys(fn (Employee $employee) => [(string) $employee->id => self::row($employee)['name']])->all();
    }

    /** @return array{id: string, number: string, name: string, company_id: string, branch_id: ?string, status: string} */
    private static function row(Employee $employee): array
    {
        return [
            'id' => (string) $employee->id,
            'number' => (string) $employee->number,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'company_id' => (string) $employee->company_id,
            'branch_id' => $employee->branch_id,
            'status' => (string) $employee->status,
        ];
    }
}
