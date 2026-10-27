<?php

namespace Modules\Restaurant\Http\Requests;

/** POST restaurant/orders/{id}/transfer (REST-05): `restaurant.order.transfer` at the order's location. */
class TransferRequest extends BoundRequest
{
    public function rules(): array
    {
        return ['waiter_id' => ['required', 'uuid']];
    }
}
