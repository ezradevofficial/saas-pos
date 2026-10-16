<?php

namespace Modules\Ecommerce\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ECOM-37: what happened to an order (`placed`, `paid`, `status_changed`,
 * `completed`), raised after commit with ids and amounts only, for
 * webhooks, Loyalty and the fiscal queue (`completed`: delivered).
 */
class OrderEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const PLACED = 'placed';

    public const PAID = 'paid';

    public const STATUS_CHANGED = 'status_changed';

    public const COMPLETED = 'completed';

    public function __construct(
        public readonly string $tenantId,
        public readonly string $kind,
        public readonly string $orderId,
        public readonly string $companyId,
        public readonly string $number,
        public readonly string $currency,
        public readonly string $totalMinor,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {}
}
