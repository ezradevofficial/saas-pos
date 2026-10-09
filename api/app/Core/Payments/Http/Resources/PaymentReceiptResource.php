<?php

namespace App\Core\Payments\Http\Resources;

use App\Core\Payments\Models\PaymentReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Money the provider reported as received (a C2B confirmation), for the
 * back office's matching list. No payer details are kept or returned.
 *
 * @mixin PaymentReceipt
 */
class PaymentReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'payment_method_id' => $this->payment_method_id,
            'provider' => $this->provider,
            'receipt' => $this->receipt,
            'amount' => ['amount_minor' => (string) $this->amount_minor, 'currency' => $this->currency],
            'account_reference' => $this->account_reference,
            'shortcode' => $this->shortcode,
            'transacted_at' => $this->transacted_at?->toIso8601String(),
            'status' => $this->status,
            'payment_intent_id' => $this->payment_intent_id,
            'matched_by' => $this->matched_by,
            'matched_at' => $this->matched_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
