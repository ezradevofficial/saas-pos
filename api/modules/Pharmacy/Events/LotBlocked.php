<?php

namespace Modules\Pharmacy\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** PHA-11, PHA-22, PHA-30: a lot became unsellable (`quarantined` or `recalled`). Ids only, after commit. */
class LotBlocked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $lotId,
        public readonly string $companyId,
        public readonly string $itemId,
        public readonly string $reason,
    ) {}
}
