<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Procurement\Models\Order;

/**
 * POST procurement/receipts (PRC-14): a draft GRN against an approved PO,
 * `procurement.receipt.create` at the receiving location (the PO's
 * delivery location by default); `{order_id, location_id?, received_at?,
 * delivery_note?, note?, lines: [{order_line_id, received_qty,
 * rejected_qty?, reject_reason?, batch_number?, expires_on?, serials?,
 * weight?}]}`. PATCH procurement/receipts/{id} (UpdateReceiptRequest)
 * takes the same fields but the PO, each optional, on a draft.
 */
class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'procurement.receipt.create');
    }

    public function rules(): array
    {
        return [
            'order_id' => ProcurementRules::id('procurement_orders', required: true),
            ...self::fields(required: true),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function fields(bool $required): array
    {
        return [
            'location_id' => ProcurementRules::id('locations'),
            'received_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'delivery_note' => ['sometimes', 'nullable', 'string', 'max:100'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            'lines.*.order_line_id' => ProcurementRules::id('procurement_order_lines', required: true),
            'lines.*.received_qty' => ['required', 'string', ProcurementRules::QTY],
            'lines.*.rejected_qty' => ['sometimes', 'nullable', 'string', ProcurementRules::QTY],
            'lines.*.reject_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines.*.batch_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'lines.*.expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'lines.*.serials' => ['sometimes', 'array', 'max:1000'],
            'lines.*.serials.*' => ['string', 'max:100'],
            'lines.*.weight' => ['sometimes', 'nullable', 'string', ProcurementRules::QTY],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                $order = Order::query()->find($this->input('order_id'));
                ProcurementRules::atLocation($this->user(), 'procurement.receipt.create', (string) ($this->input('location_id') ?? $order?->location_id));
            }
        }];
    }
}
