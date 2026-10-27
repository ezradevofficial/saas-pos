<?php

namespace Modules\Salon\Http\Requests;

use Illuminate\Validation\Rule;

/** POST salon/appointments/{id}/deposit (SAL-11): `salon.deposit.take` at the appointment. */
class DepositRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'uuid', Rule::exists('payment_methods', 'id')],
            'amount_minor' => ['required', 'regex:/^\d{1,15}$/'],
            'mode' => ['sometimes', 'string', 'in:direct,stk'],
            'phone' => ['required_if:mode,stk', 'nullable', 'string', 'max:30'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
