<?php

namespace App\Core\Billing\Http\Requests;

/**
 * SUB-04: pay an open invoice with a saved method (`payment_method_id`,
 * the tenant's own saved method; any other id is refused), or once with a
 * wallet (`provider` and `phone`) or a card checkout (`provider`
 * card_aggregator).
 */
class PayInvoiceRequest extends ManageSubscriptionRequest
{
    public function rules(): array
    {
        return [
            'payment_method_id' => ['nullable', 'uuid', 'required_without:provider'],
            'provider' => ['nullable', 'string', 'max:40', 'required_without:payment_method_id'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }
}
