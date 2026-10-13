<?php

namespace Modules\Accounting\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Tenancy\Http\Requests\ListRequest as CoreListRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * ACC-17, EXP-01: the list contract of the Accounting lists: one company
 * (the route's), `?status=` (or `all`), `?search=`, `?from=` and `?to=`
 * (calendar days), `?sort=`, pages of `?per_page`, and an export
 * (`?format=csv|xlsx|pdf`).
 */
abstract class ListRequest extends AccountingRequest
{
    use SortsAndExports;

    /** @return list<string> */
    abstract protected function statuses(): array;

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in([...$this->statuses(), 'all'])],
            'search' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.CoreListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** The company's rows, `?from`/`?to` on $dateColumn and `?status` on `status` (or a list's own rule). */
    public function filter(Builder $query, ?string $dateColumn = null): Builder
    {
        $query->where($query->qualifyColumn('company_id'), $this->company()->id);

        if ($dateColumn !== null && $this->filled('from')) {
            $query->where($query->qualifyColumn($dateColumn), '>=', $this->validated('from'));
        }

        if ($dateColumn !== null && $this->filled('to')) {
            $query->where($query->qualifyColumn($dateColumn), '<=', $this->validated('to'));
        }

        return $query;
    }

    public function status(string $default = 'all'): string
    {
        return (string) $this->validated('status', $default);
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', CoreListRequest::PER_PAGE);
    }
}
