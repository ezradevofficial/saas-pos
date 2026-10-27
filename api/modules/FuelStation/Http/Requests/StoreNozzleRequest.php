<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/nozzles (FUEL-07): `fuel.setup.manage` at the station. `{pump_id, tank_id, code}`: the tank is of the pump's station. */
class StoreNozzleRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.manage';
    }

    protected function bodyRecords(): array
    {
        return ['pump_id' => 'fuel_pumps', 'tank_id' => 'fuel_tanks'];
    }

    public function rules(): array
    {
        return [
            'pump_id' => FuelRules::id('fuel_pumps', true, true),
            'tank_id' => FuelRules::id('fuel_tanks', true, true),
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-\.]+$/'],
        ];
    }
}
