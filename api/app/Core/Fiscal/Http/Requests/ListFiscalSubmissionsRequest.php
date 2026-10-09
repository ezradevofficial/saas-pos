<?php

namespace App\Core\Fiscal\Http\Requests;

use App\Core\Fiscal\Http\Lists\FiscalSubmissionList;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;

/**
 * GET companies/{company}/fiscal-submissions (`core.fiscal.view`):
 * `?status=` a status, `pending` (queued, sending or retrying) or `all`
 * (default); `?search=` (document number); `?sort`, `?per_page` and an
 * export (FiscalSubmissionList, EXP-01).
 */
class ListFiscalSubmissionsRequest extends CompanyFiscalRequest
{
    use SortsAndExports;

    public const FILTERS = ['all', 'pending', ...FiscalSubmission::STATUSES];

    public function list(): ListDefinition
    {
        return new FiscalSubmissionList($this->company());
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in(self::FILTERS)],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
