<?php

namespace App\Core\Billing\Http\Requests;

/** SUB-04: back from the hosted card page with its reference. */
class CompleteCardSetupRequest extends ManageSubscriptionRequest
{
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:100'],
            'make_default' => ['sometimes', 'boolean'],
        ];
    }
}
