<?php

namespace App\Core\MasterData\CreditLimits\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\CreditLimits\CreditLimitChange;
use App\Core\MasterData\CreditLimits\CreditLimitChangeAccess;
use App\Core\MasterData\CreditLimits\Http\Lists\CreditLimitChangeList;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET credit-limit-changes: requests at companies where the user holds
 * `core.party.view` (RBAC-04): `?status=`, `?party=`, `?company=`,
 * `?search=` (number or party name), `?sort=`, pages of `?per_page`, and
 * an export (CreditLimitChangeList, EXP-01).
 */
class ListCreditLimitChangesRequest extends FormRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new CreditLimitChangeList;
    }

    public function authorize(): bool
    {
        return app(CreditLimitChangeAccess::class)->viewAny($this->user());
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in(CreditLimitChange::STATUSES)],
            'party' => ['sometimes', 'uuid'],
            'company' => ['sometimes', 'uuid'],
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
