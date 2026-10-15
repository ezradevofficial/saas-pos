<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Modules\Procurement\Support\Places;

/**
 * The back-office list contract of Procurement's lists (PRC-21, EXP-01,
 * ADR 008): rows where the user holds the list's view permission (at the
 * row's branch, or its location when it has one, RBAC-04), `?company=`,
 * `?branch=`, `?location=`, `?party=` (ids, checked under RLS: another
 * tenant's id does not exist), `?from=` and `?to=` (calendar days in the
 * row's company time zone), `?status=` (or `all`), `?search=`, `?sort=`,
 * pages and an export.
 */
trait ListsProcurement
{
    use SortsAndExports;

    abstract protected function viewPermission(): string;

    /** @return list<string> the list's statuses */
    abstract protected function statuses(): array;

    /** The row's location column, when it has one. */
    protected function locationColumn(): ?string
    {
        return null;
    }

    /** Whether the rows name a supplier (`party_id`). */
    protected function hasParty(): bool
    {
        return true;
    }

    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), $this->viewPermission());
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in([...$this->statuses(), 'all'])],
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'location' => ['sometimes', 'uuid', Rule::exists('locations', 'id')],
            'party' => ['sometimes', 'uuid', Rule::exists('parties', 'id')],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** $query limited to the visible rows and the request's filters. */
    public function applyFilters(Builder $query, string $dateColumn = 'created_at'): Builder
    {
        app(Places::class)->filter($query, $this->user(), $this->viewPermission(), locationColumn: $this->locationColumn());

        foreach (['company' => 'company_id', 'branch' => 'branch_id'] as $filter => $column) {
            if ($this->filled($filter)) {
                $query->where($query->qualifyColumn($column), $this->validated($filter));
            }
        }

        if ($this->filled('location') && $this->locationColumn() !== null) {
            $query->where($query->qualifyColumn($this->locationColumn()), $this->validated('location'));
        }

        if ($this->filled('party') && $this->hasParty()) {
            $query->where($query->qualifyColumn('party_id'), $this->validated('party'));
        }

        if ($this->filled('status') && $this->validated('status') !== 'all') {
            $query->where($query->qualifyColumn('status'), $this->validated('status'));
        }

        $zone = "(select coalesce(companies.timezone, 'UTC') from companies where companies.id = ".$query->qualifyColumn('company_id').')';

        if ($this->filled('from')) {
            $query->whereRaw($query->qualifyColumn($dateColumn)." >= (?::date)::timestamp at time zone {$zone}", [$this->validated('from')]);
        }

        if ($this->filled('to')) {
            $query->whereRaw($query->qualifyColumn($dateColumn)." < ((?::date) + 1)::timestamp at time zone {$zone}", [$this->validated('to')]);
        }

        $search = trim((string) $this->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $this->searchRows($q, $like));
        }

        return $query;
    }

    /** The list's `?search=`: its number by default. */
    protected function searchRows(Builder $query, string $like): void
    {
        $query->where($query->qualifyColumn('number'), 'ilike', $like);
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
