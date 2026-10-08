<?php

namespace App\Core\Currency\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/** CUR-01: (de)activate a tenant currency, set its cash rounding and, while unlocked, its decimals. */
class UpdateTenantCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('core.currency.edit', Scope::tenant()) === true;
    }

    public function rules(): array
    {
        return [
            ...TenantCurrencyRules::settings(),
            'active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return TenantCurrencyRules::attributes();
    }
}
