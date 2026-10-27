<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/tanks (FUEL-05): `fuel.setup.view` at the reader's stations; `?location_id=`, `?product_id=`, `?archived=1`. */
class ListTanksRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.view';
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'uuid'],
            'product_id' => ['sometimes', 'uuid'],
            'archived' => ['sometimes', 'boolean'],
            ...FuelRules::paging(),
        ];
    }
}
