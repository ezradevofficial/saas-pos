<?php

namespace App\Core\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SUB-04: a payment provider's webhook for the platform's own billing
 * (Paystack, pawaPay). Public: Paystack's body is checked against its
 * HMAC signature and pawaPay's optionally against its signature, and in
 * both cases the payment is re-checked with the provider before anything
 * is applied (BillingWebhooks). The body is the provider's, so nothing is
 * validated here beyond being JSON.
 */
class BillingWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
