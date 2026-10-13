<?php

namespace Modules\Accounting\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** ACC-23: a receipt or payment was recorded and posted (after commit). */
class PaymentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $paymentId,
        public readonly string $companyId,
        public readonly string $kind,
    ) {}
}
