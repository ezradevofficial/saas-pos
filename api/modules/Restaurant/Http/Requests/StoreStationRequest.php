<?php

namespace Modules\Restaurant\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST restaurant/stations (REST-08): `restaurant.station.manage` at the location. */
class StoreStationRequest extends RestaurantRequest
{
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')],
            ...StationFields::rules(true),
        ];
    }

    protected function target(): ?Scope
    {
        return $this->atLocation((string) $this->input('location_id'));
    }
}
