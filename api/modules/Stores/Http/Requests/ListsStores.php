<?php

namespace Modules\Stores\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\VisibleScope;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The back-office list contract of Stores' lists (STR-13, STR-18, EXP-01,
 * ADR 008): rows at the stores where the user holds the list's view
 * permission (RBAC-04), `?company=`, `?branch=`, `?location=`,
 * `?department=`, `?cost_centre=`, `?item=` (ids, checked under RLS:
 * another tenant's id does not exist), `?from=` and `?to=` (calendar days
 * in the row's company time zone), `?status=` (or `all`), `?search=`,
 * `?sort=`, pages and an export.
 */
trait ListsStores
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
            'department' => ['sometimes', 'uuid', Rule::exists('departments', 'id')],
            'cost_centre' => ['sometimes', 'uuid', Rule::exists('cost_centres', 'id')],
            'item' => ['sometimes', 'uuid', Rule::exists('items', 'id')],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            ...$this->extraRules(),
        ];
    }

    public function visible(): VisibleScope
    {
        return app(ScopeResolver::class)->visibleIds($this->user(), $this->viewPermission());
    }

    /** $query limited to the visible stores and the request's place, dimension, item, status and date filters. */
    public function applyFilters(Builder $query, ?string $dateColumn = null, bool $items = false): Builder
    {
        $visible = $this->visible();

        if (! $visible->all) {
            $query->whereIn($query->qualifyColumn('location_id'), $visible->idsFor(Scope::LOCATION));
        }

        foreach (['company' => 'company_id', 'location' => 'location_id', 'department' => 'department_id', 'cost_centre' => 'cost_centre_id'] as $filter => $column) {
            if ($this->filled($filter)) {
                $query->where($query->qualifyColumn($column), $this->validated($filter));
            }
        }

        if ($this->filled('branch')) {
            $query->whereIn($query->qualifyColumn('location_id'), fn ($q) => $q->select('id')->from('locations')->where('branch_id', $this->validated('branch')));
        }

        if ($this->filled('item')) {
            $items
                ? $query->where($query->qualifyColumn('item_id'), $this->validated('item'))
                : $query->whereIn($query->qualifyColumn('id'), $this->documentsWithItem((string) $this->validated('item')));
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

    /** A document list's `?item=`: the ids of its documents with a line of the item. */
    protected function documentsWithItem(string $itemId): \Closure
    {
        return fn ($q) => $q->selectRaw('null')->whereRaw('false');
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
