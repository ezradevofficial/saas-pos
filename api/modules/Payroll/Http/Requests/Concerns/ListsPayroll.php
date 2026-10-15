<?php

namespace Modules\Payroll\Http\Requests\Concerns;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Modules\Payroll\Support\Access;

/**
 * The back-office list contract of Payroll's lists (PAY-25, PAY-27,
 * EXP-01, ADR 008): rows at the places (or companies) where the user
 * holds the list's view permission, `?company=`, `?branch=`,
 * `?department=`, `?status=` (or `all`), `?search=`, `?sort=`, pages and
 * an export.
 */
trait ListsPayroll
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
            'department' => ['sometimes', 'uuid', Rule::exists('departments', 'id')],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            ...$this->extraRules(),
        ];
    }

    /**
     * $query limited as the list says (`places`: its own place columns;
     * `employee`: through `payroll_employee_id`; `companies`: company-level
     * holders only) and filtered.
     */
    public function applyFilters(Builder $query, string $limit = 'places', string $defaultStatus = 'all'): Builder
    {
        $access = app(Access::class);

        match ($limit) {
            'companies' => $access->limitCompanies($query, $this->user(), $this->viewPermission()),
            'employee' => $access->limitPlaces($query, $this->user(), $this->viewPermission(), 'payroll_employee_id'),
            default => $access->limitPlaces($query, $this->user(), $this->viewPermission()),
        };

        if ($this->filled('company')) {
            $query->where($query->qualifyColumn('company_id'), $this->validated('company'));
        }

        foreach (['branch' => 'branch_id', 'department' => 'department_id'] as $filter => $column) {
            // Runs are company rows: no branch or department of their own.
            if ($this->filled($filter) && $limit !== 'companies') {
                $limit === 'employee'
                    ? $query->whereIn($query->qualifyColumn('payroll_employee_id'), fn ($q) => $q->select('id')->from('payroll_employees')->where($column, $this->validated($filter)))
                    : $query->where($query->qualifyColumn($column), $this->validated($filter));
            }
        }

        if ($this->statuses() !== []) {
            $status = $this->validated('status', $defaultStatus);

            if ($status !== 'all') {
                $query->where($query->qualifyColumn('status'), $status);
            }
        }

        return $query;
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
