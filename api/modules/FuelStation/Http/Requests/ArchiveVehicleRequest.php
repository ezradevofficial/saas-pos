<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/vehicles/{id}/archive (FUEL-20, TEN-06): `fuel.vehicle.manage` in the company. */
class ArchiveVehicleRequest extends FuelRequest
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
        return [];
    }
}
