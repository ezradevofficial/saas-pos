<?php

namespace Modules\Restaurant\Http\Requests;

/** POST restaurant/orders/{id}/merge (REST-15): `restaurant.order.merge` at the order's location. */
class MergeRequest extends BoundRequest
{
    public function rules(): array
    {
        return ['into_order_id' => ['required', 'uuid']];
    }
}
