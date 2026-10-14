<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Procurement\Models\Rfq;

/**
 * POST procurement/quotes (PRC-04): a supplier's quote for an issued RFQ,
 * `procurement.rfq.create` at the RFQ's branch; `{rfq_id, party_id,
 * reference?, quote_date, valid_until?, currency, note?, lines: [{rfq_line_id,
 * unit_price_minor, lead_time_days?, note?}]}`. Typed again for the same
 * supplier, it replaces the previous quote.
 */
class StoreQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'procurement.rfq.create');
    }

    public function rules(): array
    {
        return [
            'rfq_id' => ProcurementRules::id('procurement_rfqs', required: true),
            'party_id' => ProcurementRules::id('parties', required: true),
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'quote_date' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.rfq_line_id' => ProcurementRules::id('procurement_rfq_lines', required: true),
            'lines.*.unit_price_minor' => ['required', 'string', ProcurementRules::MINOR],
            'lines.*.lead_time_days' => ['sometimes', 'nullable', 'integer', 'between:0,3650'],
            'lines.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                ProcurementRules::atBranch($this->user(), 'procurement.rfq.create', (string) Rfq::query()->whereKey($this->input('rfq_id'))->value('branch_id'));
            }
        }];
    }

    public function rfq(): Rfq
    {
        return Rfq::query()->findOrFail($this->validated('rfq_id'));
    }
}
