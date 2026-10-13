<?php

namespace Modules\Inventory\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\VisibleScope;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The back-office list contract of Inventory's lists (INV-19, EXP-01,
 * ADR 008): rows at the places where the user holds the list's view
 * permission (RBAC-04), `?company=`, `?branch=`, `?location=` (ids,
 * checked under RLS: another tenant's id does not exist), `?from=` and
 * `?to=` (calendar days in the row's company time zone), `?status=` (or
 * `all`), `?search=`, `?sort=`, pages and an export.
 */
trait ListsInventory
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

    /**
     * $query limited to the visible places and the request's place, status
     * and date filters. $locations: the row's location columns (a row is
     * seen when any of them is visible, a transfer from either end).
     *
     * @param  list<string>  $locations
     */
    public function applyFilters(Builder $query, array $locations = ['location_id'], ?string $dateColumn = null): Builder
    {
        $visible = $this->visible();

        if (! $visible->all) {
            $query->where(function (Builder $q) use ($locations, $visible) {
                foreach ($locations as $column) {
                    $q->orWhereIn($q->qualifyColumn($column), $visible->idsFor(Scope::LOCATION));
                }
            });
        }

        if ($this->filled('company')) {
            $query->where($query->qualifyColumn('company_id'), $this->validated('company'));
        }

        if ($this->filled('branch')) {
            $query->whereIn($query->qualifyColumn($locations[0]), fn ($q) => $q->select('id')->from('locations')->where('branch_id', $this->validated('branch')));
        }

        if ($this->filled('location')) {
            $query->where(function (Builder $q) use ($locations) {
                foreach ($locations as $column) {
                    $q->orWhere($q->qualifyColumn($column), $this->validated('location'));
                }
            });
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

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
