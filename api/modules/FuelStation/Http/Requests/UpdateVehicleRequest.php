<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FuelStation\Models\Product;
use Modules\FuelStation\Models\Vehicle;

/** PATCH fuel/vehicles/{id} (FUEL-20): `fuel.vehicle.manage` in the company. `{label?, fuel_type?, daily_litre_limit?, status?}`. */
class UpdateVehicleRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.vehicle.manage';
    }

    protected function routeCompanyRecord(): ?string
    {
        return 'fuel_vehicle';
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'fuel_type' => ['sometimes', 'nullable', 'string', Rule::in(Product::TYPES)],
            'daily_litre_limit' => FuelRules::litres(false, true),
            'status' => ['sometimes', 'string', Rule::in([Vehicle::ACTIVE, Vehicle::BLOCKED])],
        ];
    }
}
