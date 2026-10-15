<?php

namespace Modules\Inventory\Listeners;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
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
 *   Only stock items move: a made-to-order menu item (a `kit` or
 *   `non_stock` item with a recipe) is never moved here; Manufacturing
 *   consumes its recipe's ingredients instead (MFG-09, its MaterialsMoved
 *   `backflush`), so the sold item and its ingredients never both move.
 * - SaleVoided: what the sale took comes back (`sale_void`), at its cost.
 * - SaleRefunded: the refunded quantities come back (`sale_return`) from
 *   the sale line's movements, in order, at their cost and into their
 *   batches; the last units take what is left of the value exactly.
 *
 * Events may arrive in any order (queued jobs, retries). Everything about
 * one sale runs under one advisory lock: a void or refund handled before
 * its sale finds no movements and moves nothing; the sale, handled later,
 * reads the sale's state: a voided sale moves nothing, and refunds already
 * applied are posted right after it, each as its own refund's posting, so
 * the refund's own event (handled before or after) never moves stock twice.
 * The ledger posts each source once, so redelivered events move nothing.
 *
 * The listener itself only queues MoveSaleStock with the event's ids, and
 * only while Inventory is active (the job checks again): the
 * sale snapshot the event carries (for Loyalty) is never serialised into
 * Inventory's job, and nothing here can fail the till's upload.
 */
class MoveStockForSale
{
    public const SALE = 'sale';

    public const VOID = 'void';

    public const REFUND = 'refund';

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly StockLedger $ledger,
        private readonly Units $units,
    ) {}

    public function handle(SaleCompleted|SaleVoided|SaleRefunded $event): void
    {
        // Stage 7B performance review: no job for a tenant without Inventory (the upload runs in the event's tenant).
        $tenants = app(TenantContext::class);
        $active = fn () => $this->modules->isActive(InventoryServiceProvider::MODULE);

        if (! ($tenants->id() === $event->tenantId ? $active() : $tenants->run($event->tenantId, $active))) {
            return;
        }

        match (true) {
            $event instanceof SaleCompleted => MoveSaleStock::dispatch($event->tenantId, self::SALE, $event->saleId),
            $event instanceof SaleVoided => MoveSaleStock::dispatch($event->tenantId, self::VOID, $event->voidId),
            default => MoveSaleStock::dispatch($event->tenantId, self::REFUND, $event->refundId),
        };
    }

    /** Move the stock of one sale, void or refund ($kind), in the current tenant. */
    public function move(string $kind, string $id): void
    {
        if (! $this->modules->isActive(InventoryServiceProvider::MODULE)) {
            return;
        }

        match ($kind) {
            self::SALE => $this->sale($id),
            self::VOID => $this->back('pos.void', $id, SaleStockLines::forVoid($id), Movement::SALE_VOID),
            default => $this->back('pos.refund', $id, SaleStockLines::forRefund($id), Movement::SALE_RETURN),
        };
    }

    private function sale(string $saleId): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($saleId) {
            $this->lockSale($saleId);
            $sale = SaleStockLines::forSale($saleId);

            // A voided sale moved nothing yet: nothing to do (its void moved nothing either).
            if ($sale === null || $sale['status'] !== 'completed') {
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

            $this->ledger->post(new Posting($sale['company_id'], 'pos.sale', $saleId, CarbonImmutable::parse($sale['occurred_at']), null, $lines, manual: false, reference: $sale['receipt_number'] ?? null));

            // Refunds applied before this event was handled come back now.
            foreach ($sale['refund_ids'] as $refundId) {
                $this->postBack('pos.refund', $refundId, SaleStockLines::forRefund($refundId), Movement::SALE_RETURN);
            }
        });
    }

    /** One lock per sale: its posting, void and refunds never interleave. */
    private function lockSale(string $saleId): void
    {
        DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['inventory:pos-sale:'.$saleId]);
    }

    /** @param array<string, mixed>|null $source */
    private function back(string $sourceType, string $sourceId, ?array $source, string $kind): void
    {
        if ($source === null) {
            return;
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($sourceType, $sourceId, $source, $kind) {
            $this->lockSale($source['sale_id']);
            $this->postBack($sourceType, $sourceId, $source, $kind);
        });
    }

    /**
     * Under the sale's lock: from the movements of each sale line, what they
     * still hold back, at their cost.
     *
     * @param  array{sale_id: string, company_id: string, location_id: string, occurred_at: string, lines: list<array{line_id: string, sale_line_id: string, item_id: string, uom_id: string, qty: string}>}|null  $source
     */
    private function postBack(string $sourceType, string $sourceId, ?array $source, string $kind): void
    {
        if ($source === null) {
            return;
        }

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
                $outValue = BigInteger::of((string) $out->value_minor)->abs();
                $back = Movement::query()->where('reverses_movement_id', $out->id);
                $returnedQty = Qty::of((string) ((clone $back)->sum('qty') ?? '0'));
                $returnedValue = BigInteger::of((string) ((clone $back)->sum('value_minor') ?? '0'));
                $remaining = Qty::of($outQty->minus($returnedQty));
                $take = Qty::min($remaining, $left);

                if (! $take->isPositive()) {
                    continue;
                }

                // L2: the last units take what is left of the value, exactly.
                $cost = $take->isEqualTo($remaining) ? $outValue->minus($returnedValue) : Qty::share($outValue, $take, $outQty);

                $lines[] = new PostingLine(
                    $out->item_id, $out->location_id, $kind, $take,
                    sourceLineId: $line['line_id'], batchId: $out->batch_id,
                    cost: $cost,
                    reversesMovementId: $out->id,
                );
                $left = Qty::of($left->minus($take));
            }
        }

        $this->ledger->post(new Posting($source['company_id'], $sourceType, $sourceId, CarbonImmutable::parse($source['occurred_at']), null, $lines, manual: false, reference: $source['receipt_number'] ?? null));
    }
}
