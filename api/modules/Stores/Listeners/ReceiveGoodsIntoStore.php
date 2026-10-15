<?php

namespace Modules\Stores\Listeners;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Contracts\GoodsReceiptExceptions;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Stores\Models\Movement;
use Modules\Stores\Stock\Lines;
use Modules\Stores\Stock\Places;
use Modules\Stores\Stock\Qty;
use Modules\Stores\Stock\StoreLedger;

/**
 * STR-07: goods received by another module (Procurement's GRN, through
 * Inventory's GoodsReceived contract) at a store enter the store's own
 * ledger as `receipt` movements, only while Stores is active and Inventory
 * is not (with Inventory on, Inventory receives them). Never refused (the
 * goods are there): a line naming an unknown or non-stock item, or a unit
 * the item does not have, is skipped and reported back to the sender
 * (GoodsReceiptExceptions). Once per receipt.
 */
class ReceiveGoodsIntoStore implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly StoreLedger $ledger,
        private readonly Places $places,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(GoodsReceived $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive('stores') || $this->ledger->current() !== Movement::LEDGER_STORES) {
                return;
            }

            $place = $this->places->of($event->locationId);

            if ($place === null || $place['company_id'] !== $event->companyId || ! in_array($place['type'], Places::STORE_TYPES, true)) {
                return;
            }

            $lines = [];
            $exceptions = [];

            foreach ($event->lines as $line) {
                $item = Item::query()->whereKey($line['item_id'])->where('type', 'stock')->first();
                $factor = $item === null ? null : Lines::factor($item, $line['uom_id'] ?? null);

                if ($item === null || $factor === null || ! BigDecimal::of($line['qty'])->isPositive()) {
                    Log::warning('stores: goods received line skipped', ['receipt' => $event->receiptId, 'line' => $line['line_id']]);
                    $exceptions[] = ['line_id' => (string) $line['line_id'], 'code' => GoodsReceiptExceptions::skipped($item, $factor)];

                    continue;
                }

                $lines[] = [
                    'item_id' => $item->id, 'location_id' => $event->locationId, 'kind' => Movement::RECEIPT,
                    'qty' => Qty::of(BigDecimal::of($line['qty'])->multipliedBy($factor)),
                    'uom_id' => $line['uom_id'] ?? $item->base_uom_id, 'uom_qty' => BigDecimal::of($line['qty']), 'source_line_id' => $line['line_id'],
                ];
            }

            $this->ledger->post(Movement::LEDGER_STORES, $event->companyId, Movement::SOURCE_GOODS_RECEIPT, $event->receiptId,
                CarbonImmutable::parse($event->receivedAt), $event->userId, $lines, ['reference' => $event->reference], refuseNegative: false);

            // Stage 7B closing review: what could not be received shows on the sender's document.
            if ($exceptions !== []) {
                event(new GoodsReceiptExceptions($event->tenantId, 'stores', $event->receiptId, $exceptions));
            }
        });
    }
}
