<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * GET payroll/pay-groups, payroll/items (PAY-03, PAY-04): rows of the
 * companies the reader reads with `payroll.settings.view` (held at, above
 * or below the company), `?company=`, `?status=active|archived|all`,
 * `?search=`, `?per_page=`, `?page=`.
 */
class SetupListRequest extends PayrollRequest
{
    public const VIEW = 'payroll.settings.view';

    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), self::VIEW);
    }

    public function rules(): array
    {
        return [
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'status' => ['sometimes', 'string', Rule::in(['active', 'archived', 'all'])],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function applyFilters(Builder $query, array $searchColumns = []): Builder
    {
        $visible = $this->access()->visible($this->user(), self::VIEW);

        if (! $visible->all) {
            $query->whereIn($query->qualifyColumn('company_id'), $visible->companiesTouched() ?? []);
        }

        if ($this->filled('company')) {
            $query->where($query->qualifyColumn('company_id'), $this->validated('company'));
        }

        match ($this->validated('status', 'active')) {
            'active' => $query->whereNull($query->qualifyColumn('archived_at')),
            'archived' => $query->whereNotNull($query->qualifyColumn('archived_at')),
            default => null,
        };

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
