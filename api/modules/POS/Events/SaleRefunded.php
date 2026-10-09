<?php

namespace Modules\POS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** POS-13: lines of a sale were refunded (after commit, once per refund). */
class SaleRefunded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $saleId,
        public readonly string $refundId,
    ) {}
}
