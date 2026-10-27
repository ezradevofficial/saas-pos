<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;

/** POST fuel/stations (FUEL-02): `fuel.setup.manage` at the location. `{location_id, currency}`; the location must be of the tenant and not already a station. */
class StoreStationRequest extends FuelRequest
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
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')->whereNull('archived_at'), Rule::unique('fuel_stations', 'location_id')],
            'currency' => FuelRules::currency(),
        ];
    }
}
