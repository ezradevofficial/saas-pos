<?php

namespace Modules\Inventory\Listeners;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\ItemSetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Modules\Inventory\Stock\Units;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleStockLines;
use Modules\POS\Events\SaleVoided;

/**
 * INV-14: POS sales move stock, once Inventory is active for the tenant
 * (RBAC-08; with it off nothing happens). Queued after the sale commits,
 * so a sale never waits for stock or fails because of it (INV-13).
 *
 * - SaleCompleted: each stock item's quantity (in its base unit) leaves
 *   the sale's location (`sale`), batches taken earliest expiry first; a
 *   serial item is flagged `serial_not_captured` (no serial at the till).
 * - SaleVoided: what the sale took comes back (`sale_void`), at its cost.
 * - SaleRefunded: the refunded quantities come back (`sale_return`) from
 *   the sale line's movements, in order, at their cost and into their
 *   batches. Sales posted before Inventory was switched on have no
 *   movements and are not reversed.
 *
 * Idempotent: the ledger posts a source once (a redelivered event moves
 * nothing); returns are computed under a lock on the sale.
 */
class MoveStockForSale implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly StockLedger $ledger,
        private readonly Units $units,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(SaleCompleted|SaleVoided|SaleRefunded $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(InventoryServiceProvider::MODULE)) {
                return;
            }

            match (true) {
                $event instanceof SaleCompleted => $this->sale($event->saleId),
                $event instanceof SaleVoided => $this->back('pos.void', $event->voidId, SaleStockLines::forVoid($event->voidId), Movement::SALE_VOID),
                default => $this->back('pos.refund', $event->refundId, SaleStockLines::forRefund($event->refundId), Movement::SALE_RETURN),
            };
        });
    }

    private function sale(string $saleId): void
    {
        $sale = SaleStockLines::forSale($saleId);

        if ($sale === null) {
            return;
        }

        $items = Item::query()->whereKey(array_column($sale['lines'], 'item_id'))->where('type', 'stock')->get()->keyBy('id');
        $serial = ItemSetting::query()->whereIn('item_id', $items->keys())->where('tracking', ItemSetting::SERIAL)->pluck('item_id')->all();
        $lines = [];

        foreach ($sale['lines'] as $line) {
            $item = $items->get($line['item_id']);

            if ($item === null || BigDecimal::of($line['qty'])->isZero()) {
                continue;
            }

            $factor = $this->units->factor($item, $line['uom_id']);
            $flags = $factor === null ? [['code' => 'uom_unknown']] : [];
            $flags = in_array($item->id, $serial, true) ? [...$flags, ['code' => 'serial_not_captured']] : $flags;

            $lines[] = new PostingLine(
                $item->id, $sale['location_id'], Movement::SALE, $this->units->toBase(BigDecimal::of($line['qty']), $factor ?? BigDecimal::one())->negated(),
                sourceLineId: $line['line_id'], uomId: $line['uom_id'], uomQty: Qty::of($line['qty']), flags: $flags, fefo: true,
            );
        }

        $this->ledger->post(new Posting($sale['company_id'], 'pos.sale', $saleId, CarbonImmutable::parse($sale['occurred_at']), null, $lines, manual: false));
    }

    /**
     * What came back: for each line, from the movements the sale line made,
     * what they still hold back, at each one's cost.
     *
     * @param  array{sale_id: string, company_id: string, location_id: string, occurred_at: string, lines: list<array{line_id: string, sale_line_id: string, item_id: string, uom_id: string, qty: string}>}|null  $source
     */
    private function back(string $sourceType, string $sourceId, ?array $source, string $kind): void
    {
        if ($source === null) {
            return;
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($sourceType, $sourceId, $source, $kind) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['inventory:pos-sale:'.$source['sale_id']]);

            $sold = Movement::query()->where('source_type', 'pos.sale')->where('source_id', $source['sale_id'])->where('kind', Movement::SALE)
                ->orderBy('created_at')->orderBy('id')->get()->groupBy('source_line_id');
            $items = Item::query()->whereKey(array_column($source['lines'], 'item_id'))->get()->keyBy('id');
            $lines = [];

            foreach ($source['lines'] as $line) {
                $item = $items->get($line['item_id']);

                if ($item === null || ! $sold->has($line['sale_line_id'])) {
                    continue;
                }

                $left = $this->units->toBase(BigDecimal::of($line['qty']), $this->units->factor($item, $line['uom_id']) ?? BigDecimal::one());

                foreach ($sold[$line['sale_line_id']] as $out) {
                    if (! $left->isPositive()) {
                        break;
                    }

                    $outQty = Qty::of((string) $out->qty)->abs();
                    $returned = Qty::of((string) Movement::query()->where('reverses_movement_id', $out->id)->sum('qty'));
                    $take = Qty::min(Qty::of($outQty->minus($returned)), $left);

                    if (! $take->isPositive()) {
                        continue;
                    }

                    $lines[] = new PostingLine(
                        $out->item_id, $out->location_id, $kind, $take,
                        sourceLineId: $line['line_id'], batchId: $out->batch_id,
                        cost: Qty::share(BigInteger::of((string) $out->value_minor)->abs(), $take, $outQty),
                        reversesMovementId: $out->id,
                    );
                    $left = Qty::of($left->minus($take));
                }
            }

            $this->ledger->post(new Posting($source['company_id'], $sourceType, $sourceId, CarbonImmutable::parse($source['occurred_at']), null, $lines, manual: false));
        });
    }
}
