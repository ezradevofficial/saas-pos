<?php

namespace Modules\Pharmacy\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** PHA-26, PHA-30: the server found something to review on a sale it received. After commit. */
class DispenseFlagged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<string> $flags */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $saleId,
        public readonly string $locationId,
        public readonly ?string $receiptNumber,
        public readonly array $flags,
    ) {}
}
