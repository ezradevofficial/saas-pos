<?php

namespace Modules\HR\Http\Requests\Concerns;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Modules\HR\Support\Access;

/**
 * The back-office list contract of HR's lists (HR-35, HR-38, EXP-01,
 * ADR 008): rows at the places where the user holds the list's view
 * permission (the employee's place, RBAC-04), `?company=`, `?branch=`,
 * `?location=`, `?department=`, `?employee=` (ids, checked under RLS),
 * `?from=`, `?to=` (calendar days), `?status=` (or `all`), `?search=`,
 * `?sort=`, pages and an export.
 */
trait ListsHr
{
    use SortsAndExports;

    abstract protected function viewPermission(): string;

    /** @return list<string> */
    protected function statuses(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function extraRules(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return app(Access::class)->anywhere($this->user(), $this->viewPermission());
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            ...($this->statuses() === [] ? [] : ['status' => ['sometimes', 'string', Rule::in([...$this->statuses(), 'all'])]]),
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'location' => ['sometimes', 'uuid', Rule::exists('locations', 'id')],
            'department' => ['sometimes', 'uuid', Rule::exists('departments', 'id')],
            'employee' => ['sometimes', 'uuid', Rule::exists('hr_employees', 'id')],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            ...$this->extraRules(),
        ];
    }

    /**
     * $query limited to the visible places and the request's filters. Rows
     * carry `company_id`, `branch_id`, `location_id` (their employee's
     * place) and `employee_id`; `$employees` true when the rows are the
     * employees themselves.
     */
    public function applyFilters(Builder $query, ?string $dateColumn = null, bool $employees = false, string $defaultStatus = 'all', bool $viaEmployee = false): Builder
    {
        app(Access::class)->limit($query, $this->user(), $this->viewPermission(), $viaEmployee);

        foreach (['company' => 'company_id', 'branch' => 'branch_id', 'location' => 'location_id'] as $filter => $column) {
            if ($this->filled($filter)) {
                $viaEmployee
                    ? $query->whereIn($query->qualifyColumn('employee_id'), fn ($q) => $q->select('id')->from('hr_employees')->where($column, $this->validated($filter)))
                    : $query->where($query->qualifyColumn($column), $this->validated($filter));
            }
        }

        if ($this->filled('department')) {
            $employees
                ? $query->where($query->qualifyColumn('department_id'), $this->validated('department'))
                : $query->whereIn($query->qualifyColumn('employee_id'), fn ($q) => $q->select('id')->from('hr_employees')->where('department_id', $this->validated('department')));
        }

        if ($this->filled('employee')) {
            $query->where($query->qualifyColumn($employees ? 'id' : 'employee_id'), $this->validated('employee'));
        }

        if ($this->statuses() !== []) {
            $status = $this->validated('status', $defaultStatus);

            if ($status !== 'all') {
                $query->where($query->qualifyColumn('status'), $status);
            }
        }

        if ($dateColumn !== null) {
            if ($this->filled('from')) {
                $query->where($query->qualifyColumn($dateColumn), '>=', $this->validated('from'));
            }

            if ($this->filled('to')) {
                $query->where($query->qualifyColumn($dateColumn), '<=', $this->validated('to'));
            }
        }

        return $query;
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
