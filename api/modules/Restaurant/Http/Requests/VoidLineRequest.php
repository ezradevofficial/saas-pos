<?php

namespace Modules\Restaurant\Http\Requests;

/** POST restaurant/orders/{id}/lines/{line}/void (REST-11): `restaurant.order.edit` at the location; the right to void a sent line is checked by the action. */
class VoidLineRequest extends BoundRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:2', 'max:255']];
    }
}
