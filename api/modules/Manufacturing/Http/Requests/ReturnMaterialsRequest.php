<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Manufacturing\Models\MaterialReturn;

/**
 * POST manufacturing/orders/{id}/returns (MFG-12): not found unless the
 * user sees the order; `manufacturing.material.return` at its location
 * (403); `{issue_id, reason, note?, lines: [{issue_line_id, qty}]}` (lines
 * of that issue of the order, in the issue line's unit).
 */
class ReturnMaterialsRequest extends ShowOrderRequest
{
    protected function permission(): ?string
    {
        return 'manufacturing.material.return';
    }

    public function rules(): array
    {
        return [
            'issue_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', Rule::in(MaterialReturn::REASONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.issue_line_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'string', ManufacturingRules::QTY],
        ];
    }
}
