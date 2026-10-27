<?php

namespace Modules\VanSales\Http\Requests;

/** POST van-sales/collections/{id}/void (VAN-17): `van_sales.collection.void` at the collection's location. */
class VoidCollectionRequest extends BoundRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
