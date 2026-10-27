<?php

namespace Modules\FuelStation\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** FUEL-15, FUEL-33: a decided settlement changed because a sale, void or refund arrived late, and went back for approval. */
class SettlementReopened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $settlementId,
        public readonly string $shiftId,
        public readonly int $version,
        public readonly string $cause,
    ) {}
}
