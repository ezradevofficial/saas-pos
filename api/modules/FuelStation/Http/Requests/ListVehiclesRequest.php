<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/vehicles (FUEL-20): `fuel.vehicle.view` in the reader's companies; `?company_id=`, `?customer_id=`, `?search=`, `?archived=1`. */
class ListVehiclesRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.vehicle.view';
    }

    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'uuid'],
            'customer_id' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'string', 'max:60'],
            'archived' => ['sometimes', 'boolean'],
            ...FuelRules::paging(),
        ];
    }
}
