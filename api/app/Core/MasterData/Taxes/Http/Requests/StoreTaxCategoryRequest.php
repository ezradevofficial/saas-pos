<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use App\Core\MasterData\CompanyReach;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * MD-03: a tax category, shared (no `company_id`, `core.tax.edit` at
 * tenant scope) or one company's (`core.tax.edit` there), with its default
 * tax codes per company.
 */
class StoreTaxCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companyId = $this->input('company_id');

        if ($companyId === null) {
            return $this->user()->can('core.tax.edit', Scope::tenant());
        }

        // An unknown or other tenant's company fails validation (422).
        $company = is_string($companyId) && Str::isUuid($companyId) ? Company::query()->find($companyId) : null;

        if ($company === null) {
            return true;
        }

        $reach = app(CompanyReach::class);
        abort_unless($reach->reaches($this->user(), $company, ['core.tax.view', 'core.tax.edit', 'core.company.view']), 404);

        return $this->user()->can('core.tax.edit', $company);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            ...TaxCategoryRules::codeRules(),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => TaxCategoryRules::validateCodes(
            $validator, (array) $this->input('codes', []), $this->input('company_id'), $this->user(),
        )];
    }

    public function attributes(): array
    {
        return TaxCategoryRules::attributes();
    }
}
