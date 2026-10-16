<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Manufacturing\Models\Report;

/**
 * POST manufacturing/orders/{id}/reports (MFG-13): not found unless the
 * user sees the order; `manufacturing.production.report` at its location
 * (403); `{qty_good, qty_scrap?, scrap_reason?, labour_minutes?, note?,
 * by_products?: [{output_id, qty}]}` (quantities in the order's unit;
 * by-products in their item's base unit).
 */
class ReportProductionRequest extends ShowOrderRequest
{
    protected function permission(): ?string
    {
        return 'manufacturing.production.report';
    }

    public function rules(): array
    {
        return [
            'qty_good' => ['required', 'string', ManufacturingRules::QTY],
            'qty_scrap' => ['sometimes', 'nullable', 'string', ManufacturingRules::QTY],
            'scrap_reason' => ['sometimes', 'nullable', 'string', Rule::in(Report::SCRAP_REASONS)],
            'labour_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,1000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'by_products' => ['sometimes', 'array', 'max:50'],
            'by_products.*.output_id' => ['required', 'uuid'],
            'by_products.*.qty' => ['required', 'string', ManufacturingRules::QTY],
        ];
    }
}
