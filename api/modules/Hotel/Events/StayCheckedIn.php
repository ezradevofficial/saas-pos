<?php

namespace Modules\Hotel\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** HOT-34: a guest checked in (after commit, ids only). */
class StayCheckedIn implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $stayId,
        public readonly string $branchId,
        public readonly string $roomId,
        public readonly string $folioId,
    ) {}
}
