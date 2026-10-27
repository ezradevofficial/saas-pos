<?php

namespace Modules\Hotel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Models\Payment;

/**
 * HOT-14, HOT-24: a deposit, payment or refund and where it stands (an M-Pesa push is `pending` until the provider answers).
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Payment $p */
        $p = $this->resource;

        return [
            'id' => $p->id,
            'folio_id' => $p->folio_id,
            'kind' => $p->kind,
            'status' => $p->status,
            'payment_method_id' => $p->payment_method_id,
            'method_type' => $p->method_type,
            'currency' => $p->currency,
            'amount_minor' => (string) $p->amount_minor,
            'reference' => $p->reference,
            'line_id' => $p->line_id,
            'failure' => $p->failure,
            'applied_at' => Refs::at($p->converted_at),
            'created_at' => Refs::at($p->created_at),
        ];
    }
}
