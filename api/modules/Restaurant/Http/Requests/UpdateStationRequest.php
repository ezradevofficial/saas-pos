<?php

namespace Modules\Restaurant\Http\Requests;

/** PUT restaurant/stations/{id} (REST-08): `restaurant.station.manage` at the station's location. */
class UpdateStationRequest extends BoundRequest
{
    public function rules(): array
    {
        return StationFields::rules(false);
    }
}
