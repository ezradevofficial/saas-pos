<?php

namespace Modules\Procurement\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * PRC-14: a GRN was cancelled (only a draft can be; nothing was posted).
 * Raised for modules that keep something made from a receipt
 * (docs/modules/fixed-assets.md FA-21: draft assets are archived). Not an
 * Accounting source.
 */
class GoodsReceiptCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $receiptId,
        public readonly string $companyId,
        public readonly string $orderId,
        public readonly ?string $reference,
    ) {}
}
