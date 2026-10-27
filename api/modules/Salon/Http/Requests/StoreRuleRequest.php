<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST salon/commission-rules (SAL-20): `salon.commission.rule` at the company. */
class StoreRuleRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],

            'staff_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('salon_staff', 'id')],
            'service_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('salon_services', 'id')],
            'applies_to' => ['required', 'string', 'in:service,product'],
            'kind' => ['required', 'string', 'in:percent,fixed'],
            'rate' => ['required_if:kind,percent', 'nullable', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'amount_minor' => ['required_if:kind,fixed', 'nullable', 'regex:/^\d{1,15}$/'],
            'currency' => ['required_if:kind,fixed', 'nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->atCompany($this->input('company_id'));
    }
}
