<?php

namespace Modules\Payroll\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * PAY-16: a run's flow approved it (after commit), for notifications and
 * webhooks (HOOK-01 "payroll approved"). Totals per pay currency in minor
 * units (digit strings).
 */
class PayrollApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param array<string, array<string, string|int>> $totals */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $runId,
        public readonly string $number,
        public readonly string $companyId,
        public readonly string $kind,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly string $payDate,
        public readonly array $totals,
    ) {}
}
