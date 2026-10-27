<?php

namespace Modules\Salon\Http\Requests;

use Illuminate\Validation\Rule;

/** PUT salon/commission-rules/{id}: `salon.commission.rule` at the rule's company. */
class UpdateRuleRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'staff_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('salon_staff', 'id')],
            'service_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('salon_services', 'id')],
            'applies_to' => ['sometimes', 'required', 'string', 'in:service,product'],
            'kind' => ['sometimes', 'required', 'string', 'in:percent,fixed'],
            'rate' => ['required_if:kind,percent', 'nullable', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'amount_minor' => ['required_if:kind,fixed', 'nullable', 'regex:/^\d{1,15}$/'],
            'currency' => ['required_if:kind,fixed', 'nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'effective_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }
}
