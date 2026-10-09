<?php

namespace App\Core\Payments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment intent reached a final status, or a manual payment was
 * verified or found mismatched. Dispatched after commit, ids only: the
 * module that owns the reference (`reference_type`, e.g. `pos.sale`,
 * `pos.refund`) reads the intent in the tenant's context and updates its
 * own records.
 */
class PaymentIntentSettled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $intentId,
        public readonly string $referenceType,
        public readonly string $reference,
    ) {}
}
