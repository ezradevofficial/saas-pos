<?php

namespace Modules\Inventory\Listeners;

use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * INV-14: the stock of one POS sale, void or refund, moved after the
 * upload commits, in its tenant (TenantAware), retried with backoff. It
 * carries ids only (MoveStockForSale reads what it needs through POS's
 * SaleStockLines), never the event's sale snapshot.
 */
class MoveSaleStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public string $tenantId,
        public string $kind,
        public string $id,
    ) {
        $this->afterCommit();
        $this->onQueue('default');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(MoveStockForSale $stock): void
    {
        $stock->move($this->kind, $this->id);
    }
}
