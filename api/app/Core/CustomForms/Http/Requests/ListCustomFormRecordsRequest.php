<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomFields\CustomFieldLists;
use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormRecord;
use App\Core\CustomForms\CustomFormType;
use App\Core\CustomForms\Http\Lists\CustomFormRecordList;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CF-04: GET custom-form-types/{type}/records: the records the user sees
 * (RBAC-04): `?status=` (a record status, `archived` or `all`; open and
 * closed records but not archived ones by default), `?company=`,
 * `?branch=`, `?location=`, `?search=` (number), `?custom[key]=` filters
 * (CF-03), `?sort=`, pages, and an export (EXP-01).
 */
class ListCustomFormRecordsRequest extends FormRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new CustomFormRecordList($this->type());
    }

    public function type(): CustomFormType
    {
        return $this->route('custom_form_type');
    }

    public function authorize(): bool
    {
        return app(CustomFormAccess::class)->anywhere($this->user(), $this->type());
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in([...CustomFormRecord::STATUSES, 'archived', 'all'])],
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'location' => ['sometimes', 'uuid', Rule::exists('locations', 'id')],
            'search' => ['sometimes', 'string', 'max:100'],
            'custom' => ['sometimes', 'array', app(CustomFieldLists::class)->filterRule($this->type()->entity(), $this->list(), $this)],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
