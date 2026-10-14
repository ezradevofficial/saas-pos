<?php

namespace Modules\HR\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** HR-14, HR-33: an approved leave was cancelled (after commit); its days are posted back. */
class LeaveCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<array{date: string, fraction: string}> $dates */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $requestId,
        public readonly string $employeeId,
        public readonly string $companyId,
        public readonly string $leaveTypeId,
        public readonly string $days,
        public readonly array $dates,
    ) {}
}
