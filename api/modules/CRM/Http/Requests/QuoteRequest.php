<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\CRM\Models\Quote;
use Modules\CRM\Support\CrmAccess;

/**
 * POST crm/quotes and POST crm/quotes/preview (CRM-17, CRM-18):
 * `crm.quote.create` at the branch; `{branch_id, party_id, opportunity_id?,
 * currency?, quote_date?, valid_until?, tax_inclusive?, terms?, notes?,
 * owner_id?, team_id?, lines: [{item_id?, uom_id?, description?, qty,
 * unit_price_minor?, discount_percent?, tax_code_id?}]}`. PATCH
 * crm/quotes/{id}: the same fields but the branch, each optional, on a
 * draft or rejected quote the user sees (404 otherwise). Prices,
 * discounts and taxes are checked by QuotePricing for the person saving.
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $access = app(CrmAccess::class);
        $quote = $this->quote();

        if ($quote !== null) {
            abort_unless($access->sees($this->user(), $quote, 'quote'), 404);

            return $access->canAt($this->user(), 'crm.quote.create', $quote->branch_id);
        }

        return $access->anywhere($this->user(), 'crm.quote.create');
    }

    public function rules(): array
    {
        $creating = $this->quote() === null;

        return [
            ...($creating ? ['branch_id' => CrmRules::id('branches', required: true)] : []),
            'party_id' => CrmRules::id('parties', required: $creating),
            'opportunity_id' => CrmRules::id('crm_opportunities'),
            'currency' => ['sometimes', 'string', 'size:3'],
            'quote_date' => ['sometimes', 'date_format:Y-m-d'],
            'valid_until' => ['sometimes', 'date_format:Y-m-d'],
            'tax_inclusive' => ['sometimes', 'boolean'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'owner_id' => CrmRules::id('users'),
            'team_id' => CrmRules::id('crm_teams'),
            'lines' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => CrmRules::id('items'),
            'lines.*.uom_id' => CrmRules::id('uoms'),
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines.*.qty' => ['required', 'string', CrmRules::QTY],
            'lines.*.unit_price_minor' => ['sometimes', 'nullable', 'string', CrmRules::MINOR],
            'lines.*.discount_percent' => ['sometimes', 'nullable', 'string', CrmRules::PERCENT],
            'lines.*.tax_code_id' => CrmRules::id('tax_codes'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty() && $this->quote() === null) {
                    app(CrmAccess::class)->assertAt($this->user(), 'crm.quote.create', (string) $this->input('branch_id'));
                }
            },
        ];
    }

    public function quote(): ?Quote
    {
        $quote = $this->route('crm_quote');

        return $quote instanceof Quote ? $quote : null;
    }
}
