<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Billing\Models\BillingPaymentMethod;
use Illuminate\Validation\Rule;

/**
 * SUB-04: save a wallet (`provider`, `phone`) or start saving a card
 * through the hosted checkout (`kind` card: answers the page to open).
 */
class StoreBillingPaymentMethodRequest extends ManageSubscriptionRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in([BillingPaymentMethod::MOBILE_MONEY, BillingPaymentMethod::CARD])],
            'provider' => ['required_if:kind,'.BillingPaymentMethod::MOBILE_MONEY, 'nullable', 'string', 'max:40'],
            'phone' => ['required_if:kind,'.BillingPaymentMethod::MOBILE_MONEY, 'nullable', 'string', 'max:20'],
            'make_default' => ['sometimes', 'boolean'],
        ];
    }
}
