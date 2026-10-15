<?php

namespace Modules\Payroll\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * PAY-20: a payment file was built for a posted run (after commit). A hook
 * for a future dispatcher (a bank or mobile-money API); nothing listens
 * yet and no provider is called. Ids and totals only: never accounts.
 */
class PaymentFileGenerated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param array<string, string> $totals pay currency => minor units */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $runId,
        public readonly string $format,
        public readonly int $employees,
        public readonly array $totals,
        public readonly ?string $userId,
    ) {}
}
