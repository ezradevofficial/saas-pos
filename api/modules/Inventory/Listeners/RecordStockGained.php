<?php

namespace Modules\Inventory\Listeners;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Contracts\StockGained;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Places;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockInput;
use Modules\Inventory\Stock\StockLedger;

/**
 * INV-28: stock another module found in excess on its own approval (a tank
 * dip above its book stock, FUEL-30) comes in as `adjustment_in` movements
 * at an estimated cost (INV-12), once per gain (the ledger). A line naming
 * an unknown or non-stock item is skipped.
 */
class RecordStockGained implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly StockLedger $ledger,
        private readonly Places $places,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(StockGained $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(InventoryServiceProvider::MODULE)) {
                return;
            }

            $place = $this->places->of($event->locationId);

            if ($place === null || $place['company_id'] !== $event->companyId) {
                Log::warning('inventory: stock gain at a location of another company or unknown', ['gain' => $event->gainId]);

                return;
            }

            StockInput::transaction(function () use ($event) {
                $lines = [];

                foreach ($event->lines as $line) {
                    $item = Item::query()->whereKey($line['item_id'])->where('type', 'stock')->first();
                    $qty = Qty::of(BigDecimal::of($line['qty']));

                    if ($item === null || ! $qty->isPositive()) {
                        continue;
                    }

                    $lines[] = new PostingLine($item->id, $event->locationId, Movement::ADJUSTMENT_IN, $qty, sourceLineId: $line['line_id'], uomId: $item->base_uom_id, uomQty: $qty);
                }

                if ($lines !== []) {
                    $this->ledger->post(new Posting($event->companyId, 'fuel.tank_gain', $event->gainId, CarbonImmutable::parse($event->occurredAt), $event->userId, $lines, manual: false, reference: $event->reference));
                }
            });
        });
    }
}
