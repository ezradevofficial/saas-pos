<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FuelStation\Models\Product;

/** POST fuel/products (FUEL-04): `fuel.setup.manage` for the whole company. `{company_id, item_id, fuel_type, grade?}`: a stock item of the company or shared, whose base unit is the litre. */
class StoreProductRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.manage';
    }

    protected function wide(): bool
    {
        return true;
    }

    protected function companyField(): ?string
    {
        return 'company_id';
    }

    public function rules(): array
    {
        return [
            'company_id' => FuelRules::company(),
            'item_id' => ['required', 'uuid', Rule::exists('items', 'id')->where('type', 'stock')->whereNull('archived_at'), Rule::unique('fuel_products', 'item_id')],
            'fuel_type' => ['required', 'string', Rule::in(Product::TYPES)],
            'grade' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }
}
