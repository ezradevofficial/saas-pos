<?php

namespace Modules\FuelStation\Http\Requests;

/** PATCH fuel/tanks/{id} (FUEL-05): `fuel.setup.manage` at the station. `{name?, capacity_litres?, tolerance_litres?}`. */
class UpdateTankRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.manage';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_tank';
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'capacity_litres' => FuelRules::litres(false),
            'tolerance_litres' => FuelRules::litres(false, true),
        ];
    }
}
