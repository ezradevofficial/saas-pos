<?php

namespace Modules\VanSales\Http\Requests;

/** POST van-sales/loads/{id}/cancel (VAN-07): `van_sales.load.create` at the van's location. */
class CancelLoadRequest extends BoundRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
