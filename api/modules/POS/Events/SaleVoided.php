<?php

namespace Modules\POS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** POS-13: a completed sale was voided in full (after commit, once). */
class SaleVoided implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $saleId,
        public readonly string $voidId,
    ) {}
}
