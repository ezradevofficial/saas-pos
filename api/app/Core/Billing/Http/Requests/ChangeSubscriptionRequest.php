<?php

namespace App\Core\Billing\Http\Requests;

/**
 * SUB-02: confirm a quoted change with the amount shown (`expected_charge`,
 * minor units charged now); a quote that moved since is refused with the
 * new one (409).
 */
class ChangeSubscriptionRequest extends QuoteSubscriptionRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'expected_charge' => ['required', 'integer', 'min:0'],
        ];
    }
}
