<?php

namespace Modules\Inventory\Listeners;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Modules\Procurement\Events\LandedCostAllocated;

/**
 * INV-25 (owner decision 2026-10-14): landed costs allocated to stock lines
 * of goods received notes already posted (Procurement's
 * LandedCostAllocated, PRC-17) adjust the cost of what those receipts
 * brought in: one zero-quantity `cost_adjustment` movement per allocation,
 * on the receipt's own movement (its FIFO layer, or the weighted average),
 * for the units still on hand; the share of units already sold is the
 * movement's `cost_variance_minor` (an expense for Accounting, in
 * StockMoved). Once per landed cost and allocation (the ledger's
 * idempotency); allocations whose receipt Inventory never posted (the
 * module was off then) are skipped. Only while Inventory is active.
 */
class ApplyLandedCost implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly StockLedger $ledger,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(LandedCostAllocated $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(InventoryServiceProvider::MODULE)) {
                return;
            }

            $lines = [];

            foreach ($event->allocations as $allocation) {
                if (! ($allocation['stock'] ?? false) || ! ($allocation['after_receipt'] ?? false) || BigInteger::of((string) $allocation['base_amount_minor'])->isZero()) {
                    continue;
                }

                $receipt = Movement::query()->where('source_type', 'procurement.goods_receipt')->where('source_id', $allocation['receipt_id'])
                    ->where('source_line_id', $allocation['receipt_line_id'])->where('kind', Movement::RECEIPT)->first();

                if ($receipt === null) {
                    continue;
                }

                $lines[] = new PostingLine(
                    $receipt->item_id, $receipt->location_id, Movement::COST_ADJUSTMENT, Qty::zero(),
                    sourceLineId: $allocation['receipt_line_id'],
                    batchId: $receipt->batch_id,
                    cost: BigInteger::of((string) $allocation['base_amount_minor']),
                    reversesMovementId: $receipt->id,
                );
            }

            $this->ledger->post(new Posting($event->companyId, 'procurement.landed_cost', $event->landedCostId, CarbonImmutable::parse($event->date)->endOfDay()->min(CarbonImmutable::now()), null, $lines, manual: false));
        });
    }
}
