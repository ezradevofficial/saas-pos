<?php

namespace Modules\FuelStation\Http\Requests;

/** PATCH fuel/stations/{id} (FUEL-02): `fuel.setup.manage` at the station. `{currency}` (only while the station has no prices or shifts). */
class UpdateStationRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.manage';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_station';
    }

    public function rules(): array
    {
        return [
            'currency' => FuelRules::currency(),
        ];
    }
}
