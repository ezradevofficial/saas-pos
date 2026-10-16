<?php

namespace Modules\Manufacturing\Backflush;

use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * MFG-07, MFG-08: the backflush of one POS sale, void or refund, after the
 * upload commits, in its tenant (TenantAware), retried with backoff. It
 * carries ids only; Backflushes reads the sale through POS's
 * SaleStockLines.
 */
class BackflushSale implements ShouldQueue
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

    public function handle(Backflushes $backflushes): void
    {
        $backflushes->run($this->kind, $this->id);
    }
}
