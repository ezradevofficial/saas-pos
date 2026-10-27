<?php

namespace Modules\Restaurant\Http\Requests;

use Illuminate\Validation\Rule;

/** PUT restaurant/tables/{id} (REST-02): `restaurant.floor.manage` at the table's location. */
class UpdateTableRequest extends BoundRequest
{
    public function rules(): array
    {
        return [...TableFields::rules(false), 'area_id' => ['sometimes', 'uuid', Rule::exists('rest_areas', 'id')]];
    }
}
