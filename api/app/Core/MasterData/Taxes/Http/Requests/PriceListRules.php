<?php

namespace App\Core\MasterData\Taxes\Http\Requests;

use Illuminate\Validation\Rule;

/** Shared price list validation (MD-03): the currency is active in the tenant. */
final class PriceListRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(bool $updating = false): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:255'],
            'currency' => [...$required, 'string', 'regex:/^[A-Z]{3}\z/', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            'tax_inclusive' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['currency.exists' => __('core.currency.not_active')];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'name' => __('core.price_list.attributes.name'),
            'currency' => __('core.price_list.attributes.currency'),
            'tax_inclusive' => __('core.price_list.attributes.tax_inclusive'),
            'is_default' => __('core.price_list.attributes.is_default'),
        ];
    }
}
