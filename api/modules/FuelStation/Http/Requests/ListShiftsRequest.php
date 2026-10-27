<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;

/** GET fuel/shifts (FUEL-09): `fuel.shift.view` at the reader's stations; `?location_id=`, `?status=open|closed`, `?from=`, `?to=`. */
class ListShiftsRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.shift.view';
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['sometimes', 'string', Rule::in(['open', 'closed'])],
            ...FuelRules::paging(),
        ];
    }
}
