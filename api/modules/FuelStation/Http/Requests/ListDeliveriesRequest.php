<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/deliveries (FUEL-25): `fuel.delivery.view` at the reader's stations; `?tank_id=`, `?location_id=`, `?from=`, `?to=`. */
class ListDeliveriesRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.delivery.view';
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'tank_id' => ['sometimes', 'uuid'],
            ...FuelRules::paging(),
        ];
    }
}
