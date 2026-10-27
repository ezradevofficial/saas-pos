<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/tanks (FUEL-05): `fuel.setup.manage` at the station. `{location_id, product_id, code, name, capacity_litres, tolerance_litres?}`. */
class StoreTankRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.manage';
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
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-\.]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'capacity_litres' => FuelRules::litres(),
            'tolerance_litres' => FuelRules::litres(false, true),
        ];
    }
}
