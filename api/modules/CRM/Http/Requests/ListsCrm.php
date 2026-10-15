<?php

namespace Modules\CRM\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Modules\CRM\Support\CrmAccess;

/**
 * The back-office list contract of CRM's lists (CRM-32, EXP-01, ADR 008):
 * the records the user sees (CRM-08), `?company=`, `?branch=`, `?owner=`,
 * `?party=` (ids, checked under RLS: another tenant's id does not exist),
 * `?from=` and `?to=` (calendar days in the row's company time zone, on
 * the list's date column), `?status=` (a status, `archived`, or `all`;
 * archived rows only with `archived` or `all`), `?search=`, `?sort=`,
 * pages and an export.
 */
trait ListsCrm
{
    use SortsAndExports;

    /** `lead`, `opportunity`, `quote` or `activity` */
    abstract protected function resource(): string;

    /** @return list<string> */
    abstract protected function statuses(): array;

    /** The column `?from=` and `?to=` read. */
    protected function dateColumn(): string
    {
        return 'created_at';
    }

    protected function archivable(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> the list's own filters */
    protected function extraRules(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return app(CrmAccess::class)->anywhere($this->user(), "crm.{$this->resource()}.view");
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in([...$this->statuses(), 'all', ...($this->archivable() ? ['archived'] : [])])],
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'owner' => ['sometimes', 'uuid', Rule::exists('users', 'id')],
            'party' => ['sometimes', 'uuid', Rule::exists('parties', 'id')],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            ...$this->extraRules(),
        ];
    }

    /** $query limited to the visible rows and the request's filters. */
    public function applyFilters(Builder $query): Builder
    {
        app(CrmAccess::class)->visible($query, $this->user(), $this->resource());

        foreach (['company' => 'company_id', 'branch' => 'branch_id', 'owner' => 'owner_id', 'party' => 'party_id'] as $filter => $column) {
            if ($this->filled($filter)) {
                $query->where($query->qualifyColumn($column), $this->validated($filter));
            }
        }

        $status = $this->validated('status');

        if ($this->archivable() && $status !== 'all') {
            $status === 'archived' ? $query->whereNotNull($query->qualifyColumn('archived_at')) : $query->whereNull($query->qualifyColumn('archived_at'));
        }

        if ($status !== null && ! in_array($status, ['all', 'archived'], true)) {
            $query->where($query->qualifyColumn('status'), $status);
        }

        $column = $query->qualifyColumn($this->dateColumn());
        $zone = "(select coalesce(companies.timezone, 'UTC') from companies where companies.id = ".$query->qualifyColumn('company_id').')';

        if ($this->filled('from')) {
            $query->whereRaw("{$column} >= (?::date)::timestamp at time zone {$zone}", [$this->validated('from')]);
        }

        if ($this->filled('to')) {
            $query->whereRaw("{$column} < ((?::date) + 1)::timestamp at time zone {$zone}", [$this->validated('to')]);
        }

        $search = trim((string) $this->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $this->searchRows($q, $like));
        }

        $this->applyExtraFilters($query);

        return $query;
    }

    protected function applyExtraFilters(Builder $query): void {}

    abstract protected function searchRows(Builder $query, string $like): void;

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
