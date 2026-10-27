<?php

namespace Modules\VanSales\Http\Requests;

use Illuminate\Validation\Rule;

/** PUT van-sales/routes/{id} (VAN-03): `van_sales.route.manage` at the route's branch. */
class UpdateRouteRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'van_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('vs_vans', 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'visit_days' => ['sometimes', 'required', 'array', 'max:7'],
            'visit_days.*' => ['integer', 'between:1,7', 'distinct'],
        ];
    }
}
