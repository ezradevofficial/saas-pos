<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/prices (FUEL-08): `fuel.price.manage` at the station. `{location_id, product_id, price_minor, effective_from?, reference?}`. */
class StorePriceRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.price.manage';
    }

    protected function locationField(): ?string
    {
        return 'location_id';
    }

    public function rules(): array
    {
        return [
            'location_id' => FuelRules::station(),
            'product_id' => FuelRules::id('fuel_products', true, true),
            'price_minor' => FuelRules::minor(true, 1),
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
