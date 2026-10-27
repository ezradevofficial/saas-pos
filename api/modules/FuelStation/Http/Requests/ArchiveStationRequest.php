<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/stations/{id}/archive (FUEL-02, TEN-06): `fuel.setup.manage` at the station. */
class ArchiveStationRequest extends FuelRequest
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
        return [];
    }
}
