<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;

/** GET fuel/meter-resets (FUEL-17): `fuel.variance.view` at the reader's stations; `?status=`, `?location_id=`. */
class ListMeterResetsRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.variance.view';
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in(['pending', 'approved', 'rejected'])],
            ...FuelRules::paging(),
        ];
    }
}
