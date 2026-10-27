<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FuelStation\Models\Dip;

/** GET fuel/dips (FUEL-23): `fuel.dip.view` at the reader's stations; `?tank_id=`, `?location_id=`, `?kind=`, `?from=`, `?to=`. */
class ListDipsRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.dip.view';
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'tank_id' => ['sometimes', 'uuid'],
            'kind' => ['sometimes', 'string', Rule::in(Dip::KINDS)],
            ...FuelRules::paging(),
        ];
    }
}
