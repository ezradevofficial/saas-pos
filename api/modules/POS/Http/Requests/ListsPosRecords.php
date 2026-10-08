<?php

namespace Modules\POS\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The back-office list contract for POS records (POS-12, EXP-01): rows at
 * the locations where the user holds the view permission (RBAC-04),
 * `?status=` (or `all`), `?company=`, `?branch=`, `?location=`, `?from=`
 * and `?to=` (dates, UTC), `?search=`, `?sort=`, pages of `?per_page`, and
 * an export.
 */
trait ListsPosRecords
{
    use SortsAndExports;

    abstract protected function viewPermission(): string;

    /** @return list<string> */
    abstract protected function statuses(): array;

    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), $this->viewPermission());
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in([...$this->statuses(), 'all'])],
            // Under RLS: another tenant's id does not exist.
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'location' => ['sometimes', 'uuid', Rule::exists('locations', 'id')],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** $query limited to the visible locations and the request's filters ($dateColumn for from/to). */
    public function applyFilters(Builder $query, string $dateColumn): Builder
    {
        app(ScopeResolver::class)->visibleIds($this->user(), $this->viewPermission())->applyTo($query, Scope::LOCATION);

        foreach (['company' => 'company_id', 'branch' => 'branch_id', 'location' => 'location_id'] as $filter => $column) {
            if ($this->filled($filter)) {
                $query->where($query->qualifyColumn($column), $this->validated($filter));
            }
        }

        if ($this->filled('status') && $this->validated('status') !== 'all') {
            $query->where($query->qualifyColumn('status'), $this->validated('status'));
        }

        if ($this->filled('from')) {
            $query->where($query->qualifyColumn($dateColumn), '>=', $this->validated('from').' 00:00:00+00');
        }

        if ($this->filled('to')) {
            $query->where($query->qualifyColumn($dateColumn), '<', now()->parse($this->validated('to'))->addDay()->toDateString().' 00:00:00+00');
        }

        return $query;
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
