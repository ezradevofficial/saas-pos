<?php

namespace Modules\Inventory\Listeners;

use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * MFG-15: the stock of one Manufacturing document (an issue, a return, a
 * report's receipt, a sale's backflush or its return), moved after the
 * document commits, in its tenant (TenantAware), retried with backoff (a
 * return waits for its origin). It carries the event's ids, quantities
 * and costs, never anything read from Manufacturing's tables.
 */
class MoveManufacturingStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /** @param array<string, mixed> $document */
    public function __construct(
        public string $tenantId,
        public array $document,
    ) {
        $this->afterCommit();
        $this->onQueue('default');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(MoveStockForManufacturing $stock): void
    {
        $stock->move($this->document);
    }
}
