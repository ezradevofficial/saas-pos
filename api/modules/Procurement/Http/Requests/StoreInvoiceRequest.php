<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Procurement\Models\Order;

/**
 * POST procurement/invoices (PRC-15): a supplier invoice against a PO,
 * `procurement.invoice.create` at the PO's branch; `{order_id,
 * supplier_reference, invoice_date, due_date?, tax_inclusive?, lines:
 * [{order_line_id, qty, unit_price_minor?, tax_code_id?}]}` (the price and
 * tax code default to the PO line's). PATCH procurement/invoices/{id}
 * (UpdateInvoiceRequest) takes the same fields but the PO, each optional.
 */
class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'procurement.invoice.create');
    }

    public function rules(): array
    {
        return [
            ...self::fields(required: true),
            'order_id' => ProcurementRules::id('procurement_orders', required: true),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function fields(bool $required): array
    {
        return [
            'supplier_reference' => [$required ? 'required' : 'sometimes', 'string', 'max:100'],
            'invoice_date' => [$required ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'tax_inclusive' => ['sometimes', 'boolean'],
            'lines' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            'lines.*.order_line_id' => ProcurementRules::id('procurement_order_lines', required: true),
            'lines.*.qty' => ['required', 'string', ProcurementRules::QTY],
            'lines.*.unit_price_minor' => ['sometimes', 'string', ProcurementRules::MINOR],
            'lines.*.tax_code_id' => ProcurementRules::id('tax_codes'),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                ProcurementRules::atBranch($this->user(), 'procurement.invoice.create', (string) Order::query()->whereKey($this->input('order_id'))->value('branch_id'));
            }
        }];
    }
}
