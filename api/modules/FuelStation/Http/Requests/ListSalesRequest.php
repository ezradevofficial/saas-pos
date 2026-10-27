<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/sales (FUEL-18): `fuel.sale.view` at the reader's stations; `?location_id=`, `?shift_id=`, `?flagged=1` (sales with review flags), `?product_id=`, `?from=`, `?to=`. */
class ListSalesRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.sale.view';
    }

    public function rules(): array
    {
        return [
            'location_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'shift_id' => ['sometimes', 'uuid'],
            'product_id' => ['sometimes', 'uuid'],
            'flagged' => ['sometimes', 'boolean'],
            ...FuelRules::paging(),
        ];
    }
}
