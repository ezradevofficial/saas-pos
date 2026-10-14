<?php

namespace Modules\Accounting\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** ACC-08, ACC-23: a period was closed (after commit). */
class PeriodClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $periodId,
        public readonly string $companyId,
    ) {}
}
