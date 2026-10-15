<?php

namespace Modules\Manufacturing\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Modules\Manufacturing\Support\Places;

/**
 * The back-office list contract of Manufacturing's lists (MFG-21, MFG-27,
 * EXP-01, ADR 008): rows the user may see with the list's view permission
 * (at their location, RBAC-04; BOM lists by company), `?company=`,
 * `?branch=`, `?location=`, `?item=` (ids, checked under RLS: another
 * tenant's id does not exist), `?from=` and `?to=` (calendar days in the
 * row's company time zone), `?status=` (or `all`), `?search=`, `?sort=`,
 * pages and an export.
 */
trait ListsManufacturing
{
    use SortsAndExports;

    abstract protected function viewPermission(): string;

    /** @return list<string> the list's statuses (none: no status filter) */
    protected function statuses(): array
    {
        return [];
    }

    /** @return array<string, mixed> the list's own filters */
    protected function extraRules(): array
    {
        return [];
    }

    /** Whether the list's rows are company data (BOMs) rather than at a location. */
    protected function byCompany(): bool
    {
        return false;
    }

    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), $this->viewPermission());
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            ...($this->statuses() === [] ? [] : ['status' => ['sometimes', 'string', Rule::in([...$this->statuses(), 'all'])]]),
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'location' => ['sometimes', 'uuid', Rule::exists('locations', 'id')],
            'item' => ['sometimes', 'uuid', Rule::exists('items', 'id')],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            ...$this->extraRules(),
        ];
    }

    /** $query (rows with company_id and, unless by company, location_id) limited to what the user sees and the request's filters. */
    public function applyFilters(Builder $query, ?string $dateColumn = null, ?string $itemColumn = 'item_id'): Builder
    {
        $places = app(Places::class);
        $this->byCompany()
            ? $places->filterCompanies($query, $this->user(), $this->viewPermission())
            : $places->filterLocations($query, $this->user(), $this->viewPermission());

        if ($this->filled('company')) {
            $query->where($query->qualifyColumn('company_id'), $this->validated('company'));
        }

        if (! $this->byCompany() && $this->filled('location')) {
            $query->where($query->qualifyColumn('location_id'), $this->validated('location'));
        }

        if (! $this->byCompany() && $this->filled('branch')) {
            $query->whereIn($query->qualifyColumn('location_id'), fn ($q) => $q->select('id')->from('locations')->where('branch_id', $this->validated('branch')));
        }

        if ($itemColumn !== null && $this->filled('item')) {
            $query->where($query->qualifyColumn($itemColumn), $this->validated('item'));
        }

        if ($this->statuses() !== [] && $this->filled('status') && $this->validated('status') !== 'all') {
            $query->where($query->qualifyColumn('status'), $this->validated('status'));
        }

        if ($dateColumn !== null) {
            $zone = '(select companies.timezone from companies where companies.id = '.$query->qualifyColumn('company_id').')';

            if ($this->filled('from')) {
                $query->whereRaw($query->qualifyColumn($dateColumn)." >= (?::date)::timestamp at time zone coalesce({$zone}, 'UTC')", [$this->validated('from')]);
            }

            if ($this->filled('to')) {
                $query->whereRaw($query->qualifyColumn($dateColumn)." < ((?::date) + 1)::timestamp at time zone coalesce({$zone}, 'UTC')", [$this->validated('to')]);
            }
        }

        return $query;
    }

    /** `?search=` over $columns (ilike). */
    public function applyTextSearch(Builder $query, array $columns): Builder
    {
        $search = trim((string) $this->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function (Builder $q) use ($columns, $like) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'ilike', $like);
                }
            });
        }

        return $query;
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
