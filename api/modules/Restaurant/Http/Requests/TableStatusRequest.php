<?php

namespace Modules\Restaurant\Http\Requests;

use Illuminate\Validation\Rule;

/** POST restaurant/tables/{id}/status (REST-02): the manual status; `restaurant.floor.manage` at the location. */
class TableStatusRequest extends BoundRequest
{
    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['free', 'reserved', 'cleaning', 'blocked'])]];
    }
}
