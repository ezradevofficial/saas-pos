<?php

namespace Modules\Restaurant\Http\Requests;

/** POST restaurant/orders/{id}/cancel: `restaurant.order.edit` at the order's location. */
class CancelOrderRequest extends BoundRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:2', 'max:255']];
    }
}
