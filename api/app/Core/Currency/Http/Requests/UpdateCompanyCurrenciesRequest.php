<?php

namespace App\Core\Currency\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * CUR-02: replace the base currency and the ordered reporting currencies
 * (at most three, checked by the controller as `too_many_reporting_currencies`).
 * Every currency must be active in the tenant (row-level security scopes
 * the lookups); reporting currencies are distinct and differ from the base.
 */
class UpdateCompanyCurrenciesRequest extends CompanyCurrenciesRequest
{
    protected string $permission = 'core.currency.edit';

    public function rules(): array
    {
        $active = Rule::exists('tenant_currencies', 'code')->where('active', true);

        return [
            'base_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', $active],
            'reporting_currencies' => ['present', 'array'],
            'reporting_currencies.*' => ['required', 'string', 'regex:/^[A-Z]{3}$/', 'distinct', 'different:base_currency', $active],
        ];
    }

    public function messages(): array
    {
        return [
            'base_currency.exists' => __('core.currency.not_active'),
            'reporting_currencies.*.exists' => __('core.currency.not_active'),
        ];
    }

    public function attributes(): array
    {
        return [
            'base_currency' => __('core.currency.attributes.base_currency'),
            'reporting_currencies' => __('core.currency.attributes.reporting_currencies'),
            'reporting_currencies.*' => __('core.currency.attributes.reporting_currency'),
        ];
    }
}
