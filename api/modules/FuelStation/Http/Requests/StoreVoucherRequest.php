<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FuelStation\Models\Product;

/** POST fuel/vouchers (FUEL-21): `fuel.voucher.manage` in the company. `{company_id, value_minor, currency, code?, party_id?, fuel_type?, expires_on?}`. */
class StoreVoucherRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.voucher.manage';
    }

    protected function companyField(): ?string
    {
        return 'company_id';
    }

    public function rules(): array
    {
        return [
            'company_id' => FuelRules::company(),
            'value_minor' => FuelRules::minor(true, 1),
            'currency' => FuelRules::currency(),
            'code' => ['sometimes', 'nullable', 'string', 'min:4', 'max:40', 'regex:/^[A-Za-z0-9\-]+$/'],
            'party_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
            'fuel_type' => ['sometimes', 'nullable', 'string', Rule::in(Product::TYPES)],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }
}
