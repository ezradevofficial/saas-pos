<?php

namespace Modules\POS\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/** POST pos/sales: a batch of completed sales from the till (POS-01, POS-09). */
class UploadSalesRequest extends FormRequest
{
    use UploadRules;

    public const MAX = 50;

    public function rules(): array
    {
        return [
            'sales' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'sales.*.id' => $this->deviceId(),
            'sales.*.shift_id' => ['required', 'uuid'],
            'sales.*.cashier_id' => ['required', 'uuid'],
            'sales.*.customer_id' => ['nullable', 'uuid'],
            'sales.*.receipt_seq' => ['required', 'integer', 'min:1'],
            'sales.*.receipt_number' => ['required', 'string', 'max:80'],
            'sales.*.sold_at' => ['required', 'date'],
            'sales.*.offline' => ['sometimes', 'boolean'],
            'sales.*.currency' => $this->currencyCode(),
            'sales.*.price_list_id' => ['nullable', 'uuid'],

            'sales.*.lines' => ['required', 'array', 'min:1', 'max:500'],
            'sales.*.lines.*.id' => $this->deviceId(),
            'sales.*.lines.*.item_id' => ['required', 'uuid'],
            'sales.*.lines.*.item_name' => ['nullable', 'string', 'max:255'],
            'sales.*.lines.*.uom_id' => ['required', 'uuid'],
            'sales.*.lines.*.qty' => $this->quantity(),
            'sales.*.lines.*.unit_price_minor' => $this->minor(),
            'sales.*.lines.*.list_price_minor' => $this->minor(required: false),
            'sales.*.lines.*.price_list_id' => ['nullable', 'uuid'],
            'sales.*.lines.*.tax_inclusive' => ['required', 'boolean'],
            'sales.*.lines.*.discount_minor' => $this->minor(),
            'sales.*.lines.*.tax_code_id' => ['nullable', 'uuid'],
            'sales.*.lines.*.tax_rate' => ['nullable', 'string', 'regex:/^\d{1,5}(\.\d{1,4})?$/'],
            'sales.*.lines.*.tax_minor' => $this->minor(),
            'sales.*.lines.*.total_minor' => $this->minor(),
            ...$this->overrideRules('sales.*.lines.*.override'),

            'sales.*.totals' => ['required', 'array'],
            'sales.*.totals.subtotal_minor' => $this->minor(),
            'sales.*.totals.discount_minor' => $this->minor(),
            'sales.*.totals.tax_minor' => $this->minor(),
            'sales.*.totals.total_minor' => $this->minor(),

            ...$this->paymentRules('sales.*.payments'),
            'sales.*.change' => ['nullable', 'array'],
            'sales.*.change.currency' => ['required_with:sales.*.change', 'string', 'regex:/^[A-Z]{3}$/'],
            'sales.*.change.amount_minor' => ['required_with:sales.*.change', ...array_slice($this->minor(required: false), 1)],
            ...$this->rateRules('sales.*.change.rate'),
        ];
    }
}
