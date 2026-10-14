<?php

namespace Modules\HR\Http\Requests\Concerns;

use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Modules\HR\Support\Access;

/**
 * Lists of company setup rows (positions, leave types, shifts, rotations,
 * rosters, time rules, periods, devices, templates, cycles): rows of the
 * companies the user reads with the list's view permission (held at, above
 * or below the company, HR-38), `?company=`, `?status=active|archived|all`
 * for archivable rows, `?search=`, `?per_page=`, `?page=`.
 */
trait ListsSetup
{
    abstract protected function viewPermission(): string;

    protected function archivable(): bool
    {
        return false;
    }

    public function authorize(): bool
    {
        return app(Access::class)->anywhere($this->user(), $this->viewPermission());
    }

    public function rules(): array
    {
        return [
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            ...($this->archivable() ? ['status' => ['sometimes', 'string', Rule::in(['active', 'archived', 'all'])]] : []),
        ];
    }

    public function applyFilters(Builder $query, array $searchColumns = []): Builder
    {
        $visible = app(Access::class)->visible($this->user(), $this->viewPermission());

        if (! $visible->all) {
            $query->whereIn($query->qualifyColumn('company_id'), $visible->companiesTouched() ?? []);
        }

        if ($this->filled('company')) {
            $query->where($query->qualifyColumn('company_id'), $this->validated('company'));
        }

        if ($this->archivable()) {
            match ($this->validated('status', 'active')) {
                'active' => $query->whereNull($query->qualifyColumn('archived_at')),
                'archived' => $query->whereNotNull($query->qualifyColumn('archived_at')),
                default => null,
            };
        }

        $search = trim((string) $this->validated('search', ''));

        if ($search !== '' && $searchColumns !== []) {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function (Builder $q) use ($searchColumns, $like) {
                foreach ($searchColumns as $column) {
                    $q->orWhere($q->qualifyColumn($column), 'ilike', $like);
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
