<?php

namespace Modules\Restaurant\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST restaurant/areas (REST-02): `restaurant.floor.manage` at the location. */
class StoreAreaRequest extends RestaurantRequest
{
    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->atLocation((string) $this->input('location_id'));
    }
}
