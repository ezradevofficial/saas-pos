<?php

namespace Modules\Inventory\Listeners;

use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ECOM-18: the stock of one online order's hand-over or return, moved
 * after the order commits, in its tenant (TenantAware), retried with
 * backoff (a return waits for its hand-over). It carries the event's ids
 * and quantities, never anything read from E-commerce's tables.
 */
class MoveOrderStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /** @param array<string, mixed> $order */
    public function __construct(
        public string $tenantId,
        public string $kind,
        public array $order,
    ) {
        $this->afterCommit();
        $this->onQueue('default');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(MoveStockForOrders $stock): void
    {
        $stock->move($this->kind, $this->order);
    }
}
