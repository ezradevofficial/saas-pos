<?php

namespace Modules\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * INV-08, INV-16: a transfer was received. `receivedMinor` is the value
 * that arrived (no cost changes hands between locations of a company);
 * `lostMinor` is what was written off in transit, a cost for Accounting.
 * Minor units of the company's base currency as digit strings.
 */
class TransferReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $transferId,
        public readonly string $companyId,
        public readonly string $fromLocationId,
        public readonly string $toLocationId,
        public readonly string $currency,
        public readonly string $receivedMinor,
        public readonly string $lostMinor,
    ) {}
}
