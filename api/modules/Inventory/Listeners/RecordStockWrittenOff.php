<?php

namespace Modules\Inventory\Listeners;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Contracts\StockWrittenOff;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\ItemSetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Places;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockInput;
use Modules\Inventory\Stock\StockLedger;

/**
 * INV-26: stock another module wrote off on its own approval leaves as
 * `adjustment_out` movements at the company's cost, never refused (the
 * goods are gone; a shortfall is flagged `negative_stock`), batches named
 * by the sender when Inventory tracks them, else earliest expiry first.
 * Once per write-off (the ledger). A line naming an unknown or non-stock
 * item is skipped.
 */
class RecordStockWrittenOff implements ShouldQueue
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

    public function handle(StockWrittenOff $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(InventoryServiceProvider::MODULE)) {
                return;
            }

            $place = $this->places->of($event->locationId);

            if ($place === null || $place['company_id'] !== $event->companyId) {
                Log::warning('inventory: write-off at a location of another company or unknown', ['write_off' => $event->writeOffId]);

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

                    $tracking = ItemSetting::query()->where('item_id', $item->id)->value('tracking') ?? ItemSetting::NONE;
                    $batch = $tracking === ItemSetting::BATCH && ! empty($line['batch_number'])
                        ? Batch::query()->where('item_id', $item->id)->whereRaw('lower(batch_number) = lower(?)', [$line['batch_number']])->value('id')
                        : null;

                    $lines[] = new PostingLine(
                        $item->id, $event->locationId, Movement::ADJUSTMENT_OUT, $qty->negated(),
                        sourceLineId: $line['line_id'], batchId: $batch, uomId: $item->base_uom_id, uomQty: $qty,
                        fefo: $tracking === ItemSetting::BATCH && $batch === null,
                    );
                }

                if ($lines !== []) {
                    $this->ledger->post(new Posting($event->companyId, $event->source, $event->writeOffId, CarbonImmutable::parse($event->occurredAt), $event->userId, $lines, manual: false, reference: $event->reference));
                }
            });
        });
    }
}
