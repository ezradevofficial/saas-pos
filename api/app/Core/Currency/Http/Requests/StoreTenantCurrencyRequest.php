<?php

namespace App\Core\Currency\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CUR-01: activate a current ISO currency for the tenant. Tenant-wide, so
 * `core.currency.edit` at tenant scope. A currency already in the tenant's
 * list (even inactive) is changed with PATCH instead.
 */
class StoreTenantCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('core.currency.edit', Scope::tenant()) === true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'regex:/^[A-Z]{3}$/',
                Rule::exists('currencies', 'code')->where('active_in_iso', true),
                // Row-level security limits this to the current tenant.
                Rule::unique('tenant_currencies', 'code'),
            ],
            ...TenantCurrencyRules::settings(),
        ];
    }

    public function messages(): array
    {
        return ['code.exists' => __('core.currency.not_in_catalogue')];
    }

    public function attributes(): array
    {
        return TenantCurrencyRules::attributes();
    }
}
