<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company $company */
        $company = $this->route('company');

        // RBAC-04: out of scope is not found, never forbidden.
        abort_unless($this->user()->can('view', $company), 404);

        return $this->user()->can('update', $company);
    }

    public function rules(): array
    {
        $rules = CompanyRules::rules(updating: true);

        // CUR-02: as for PUT companies/{company}/currencies, the base
        // currency is not also one of the company's reporting currencies.
        $rules['base_currency'][] = Rule::unique('company_reporting_currencies', 'code')
            ->where('company_id', $this->route('company')->id);

        return $rules;
    }

    public function messages(): array
    {
        return ['base_currency.unique' => __('core.currency.base_is_reporting')];
    }
}
