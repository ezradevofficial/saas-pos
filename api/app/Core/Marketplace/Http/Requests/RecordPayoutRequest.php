<?php

namespace App\Core\Marketplace\Http\Requests;

use App\Core\Marketplace\AppPricing;
use Illuminate\Validation\Rule;

/** MKT-03: staff record a manual payout: the currency, the amount in minor units and the payment's reference. */
class RecordPayoutRequest extends PayoutsRequest
{
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', Rule::in(AppPricing::CURRENCIES)],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'reference' => ['required', 'string', 'min:3', 'max:100'],
        ];
    }
}
