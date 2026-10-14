<?php

namespace Modules\Inventory\Listeners;

use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * STR-08: the stock of one Stores issue or return, moved after the Stores
 * document commits, in its tenant (TenantAware), retried with backoff (a
 * return waits for its issue). It carries the event's ids and quantities,
 * never anything read from Stores' tables.
 */
class MoveStoreStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /** @param array<string, mixed> $document */
    public function __construct(
        public string $tenantId,
        public string $kind,
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

    public function handle(MoveStockForStores $stock): void
    {
        $stock->move($this->kind, $this->document);
    }
}
