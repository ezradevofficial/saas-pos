<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/prices (FUEL-08): `fuel.price.view` at the reader's stations; `?location_id=`, `?product_id=`, `?current=1` (only the price in force now), `?archived=1`. */
class ListPricesRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.price.view';
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'uuid'],
            'product_id' => ['sometimes', 'uuid'],
            'current' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
            ...FuelRules::paging(),
        ];
    }
}
