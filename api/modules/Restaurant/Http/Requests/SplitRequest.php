<?php

namespace Modules\Restaurant\Http\Requests;

use Illuminate\Validation\Rule;

/** POST restaurant/orders/{id}/split (REST-14): a preview, `restaurant.order.close` at the order's location. */
class SplitRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['full', 'item', 'seat', 'equal'])],
            'parts' => ['sometimes', 'integer', 'min:2', 'max:20'],
            'groups' => ['sometimes', 'array', 'max:20'],
            'groups.*' => ['array', 'max:200'],
            'groups.*.*' => ['uuid'],
            'tip_minor' => ['sometimes', 'string', 'regex:/^\d{1,15}$/'],
            'tip_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
