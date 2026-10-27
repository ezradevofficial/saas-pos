<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FuelStation\Models\Product;

/** PATCH fuel/products/{id} (FUEL-04): `fuel.setup.manage` for the whole company. `{fuel_type?, grade?}`. */
class UpdateProductRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.manage';
    }

    protected function routeCompanyRecord(): ?string
    {
        return 'fuel_product';
    }

    protected function wide(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fuel_type' => ['sometimes', 'string', Rule::in(Product::TYPES)],
            'grade' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }
}
