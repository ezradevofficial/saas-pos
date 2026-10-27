<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/tanks/{id} (FUEL-05, FUEL-24): `fuel.setup.view` at the station. */
class ShowTankRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.view';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_tank';
    }

    public function rules(): array
    {
        return [];
    }
}
