<?php

namespace Modules\FuelStation\Http\Requests;

/** PATCH fuel/nozzles/{id} (FUEL-07): `fuel.setup.manage` at the station. `{tank_id?}` (not while the nozzle is on an open shift). */
class UpdateNozzleRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.manage';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_nozzle';
    }

    protected function bodyRecords(): array
    {
        return ['tank_id' => 'fuel_tanks'];
    }

    public function rules(): array
    {
        return [
            'tank_id' => FuelRules::id('fuel_tanks', false, true),
        ];
    }
}
