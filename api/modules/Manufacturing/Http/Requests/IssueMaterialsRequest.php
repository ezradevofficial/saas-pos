<?php

namespace Modules\Manufacturing\Http\Requests;

/**
 * POST manufacturing/orders/{id}/issues (MFG-12): not found unless the user
 * sees the order; `manufacturing.material.issue` at its location (403);
 * `{note?, lines: [{material_id, qty, uom_id?}]}` (a material line of the
 * order, a positive decimal string in a unit of its item).
 */
class IssueMaterialsRequest extends ShowOrderRequest
{
    protected function permission(): ?string
    {
        return 'manufacturing.material.issue';
    }

    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.material_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'string', ManufacturingRules::QTY],
            'lines.*.uom_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
