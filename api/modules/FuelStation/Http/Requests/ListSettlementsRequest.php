<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FuelStation\Models\Settlement;

/** GET fuel/settlements (FUEL-13): `fuel.variance.view` at the reader's stations; `?location_id=`, `?status=`, `?attendant_id=`, `?from=`, `?to=`. */
class ListSettlementsRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.variance.view';
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['sometimes', 'string', Rule::in(Settlement::STATUSES)],
            'attendant_id' => ['sometimes', 'uuid'],
            ...FuelRules::paging(),
        ];
    }
}
