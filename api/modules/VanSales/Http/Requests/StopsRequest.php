<?php

namespace Modules\VanSales\Http\Requests;

use Illuminate\Validation\Rule;

/** PUT van-sales/routes/{id}/stops (VAN-04): `van_sales.route.manage` at the route's branch; the list replaces the stops, in order. */
class StopsRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'stops' => ['present', 'array', 'max:500'],
            'stops.*.customer_id' => ['required', 'uuid', 'distinct', Rule::exists('parties', 'id')],
            'stops.*.visit_days' => ['sometimes', 'nullable', 'array', 'max:7'],
            'stops.*.visit_days.*' => ['integer', 'between:1,7', 'distinct'],
        ];
    }
}
