<?php

namespace Modules\Restaurant\Http\Requests;

/** PUT restaurant/areas/{id} (REST-02): `restaurant.floor.manage` at the area's location. */
class UpdateAreaRequest extends BoundRequest
{
    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:120'], 'sort' => ['sometimes', 'integer', 'min:0', 'max:9999']];
    }
}
