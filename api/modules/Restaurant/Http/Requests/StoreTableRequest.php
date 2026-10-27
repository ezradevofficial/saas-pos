<?php

namespace Modules\Restaurant\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST restaurant/tables (REST-02): `restaurant.floor.manage` at the location. */
class StoreTableRequest extends RestaurantRequest
{
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')],
            'area_id' => ['required', 'uuid', Rule::exists('rest_areas', 'id')],
            ...TableFields::rules(true),
        ];
    }

    protected function target(): ?Scope
    {
        return $this->atLocation((string) $this->input('location_id'));
    }
}
