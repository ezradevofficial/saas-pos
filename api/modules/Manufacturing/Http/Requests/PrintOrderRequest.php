<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Manufacturing\Prints\OrderPrints;

/**
 * GET manufacturing/orders/{id}/print (MFG-24, TPL-04): not found unless
 * the user sees the order; `?document=production_order|pick_list`
 * (default production_order), `?format=html|pdf` (default html).
 */
class PrintOrderRequest extends ShowOrderRequest
{
    public function rules(): array
    {
        return [
            'document' => ['sometimes', 'string', Rule::in(array_keys(OrderPrints::TYPES))],
            'format' => ['sometimes', 'string', 'in:html,pdf'],
        ];
    }

    public function documentType(): string
    {
        return OrderPrints::TYPES[(string) $this->validated('document', 'production_order')];
    }

    public function wantsPdf(): bool
    {
        return $this->validated('format', 'html') === 'pdf';
    }
}
