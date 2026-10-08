<?php

namespace App\Core\Payments\Http\Resources;

use App\Core\Payments\Models\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment intent for the till (polling) and the back office. The phone
 * number is always masked; the provider's raw answer is never returned,
 * only its result code and a safe message. Amounts are minor units as
 * strings with the currency (ADR 003).
 *
 * @mixin PaymentIntent
 */
class PaymentIntentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'location_id' => $this->location_id,
            'device_id' => $this->device_id,
            'payment_method_id' => $this->payment_method_id,
            'provider' => $this->provider,
            'purpose' => $this->purpose,
            'mode' => $this->mode,
            'status' => $this->status,
            'verification' => $this->verification,
            'amount' => ['amount_minor' => (string) $this->amount_minor, 'currency' => $this->currency],
            'phone' => $this->maskedPhone(),
            'reference_type' => $this->reference_type,
            'reference' => $this->reference,
            'account_reference' => $this->account_reference,
            'receipt' => $this->provider_receipt,
            'result_code' => $this->result_code,
            'message' => $this->result_message,
            'original_intent_id' => $this->original_intent_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
