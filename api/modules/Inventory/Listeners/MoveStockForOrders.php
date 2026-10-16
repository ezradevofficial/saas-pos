<?php

namespace Modules\Inventory\Listeners;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ecommerce\Events\OrderFulfilled;
use Modules\Ecommerce\Events\OrderReturned;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\ItemSetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Places;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use RuntimeException;

/**
 * ECOM-18, ECOM-31 (docs/modules/ecommerce.md): online orders move stock
 * while Inventory is active (RBAC-08; with it off nothing happens),
 * queued after the order's hand-over or return commits, with the event's
 * own lines (Inventory never reads E-commerce's tables).
 *
 * - OrderFulfilled: each stock item's base quantity leaves the order's
 *   location (`ecommerce.order`, kind `sale`), batches earliest expiry
 *   first; never refused (a shortfall is flagged `negative_stock`, INV-13);
 *   serial items are flagged `serial_not_captured`.
 * - OrderReturned: everything the order took comes back
 *   (`ecommerce.return`, kind `sale_return`) at its cost, into its batches.
 *   A return handled before its order is retried by its job.
 *
 * Both run under one advisory lock per order; the ledger posts each source
 * once, so a redelivered event moves nothing.
 */
class MoveStockForOrders
{
    public const FULFIL = 'fulfil';

    public const RETURN = 'return';

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly StockLedger $ledger,
        private readonly Places $places,
    ) {}

    public function handle(OrderFulfilled|OrderReturned $event): void
    {
        $tenants = app(TenantContext::class);
        $active = fn () => $this->modules->isActive(InventoryServiceProvider::MODULE);

        if (! ($tenants->id() === $event->tenantId ? $active() : $tenants->run($event->tenantId, $active))) {
            return;
        }

        MoveOrderStock::dispatch($event->tenantId, $event instanceof OrderFulfilled ? self::FULFIL : self::RETURN, [
            'order_id' => $event->orderId, 'number' => $event->number, 'company_id' => $event->companyId, 'location_id' => $event->locationId,
            'at' => $event->at, 'user_id' => $event->userId, 'lines' => $event instanceof OrderFulfilled ? $event->lines : [],
        ]);
    }

    /** @param array{order_id: string, number: string, company_id: string, location_id: string, at: string, user_id: ?string, lines: list<array<string, string>>} $order */
    public function move(string $kind, array $order): void
    {
        if (! $this->modules->isActive(InventoryServiceProvider::MODULE)) {
            return;
        }

        $place = $this->places->of($order['location_id']);

        if ($place === null || $place['company_id'] !== $order['company_id']) {
            return;
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($kind, $order) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['inventory:ecommerce-order:'.$order['order_id']]);

            $kind === self::FULFIL ? $this->fulfil($order) : $this->back($order);
        });
    }

    private function fulfil(array $order): void
    {
        $items = Item::query()->whereKey(array_column($order['lines'], 'item_id'))->where('type', 'stock')->get()->keyBy('id');
        $serial = ItemSetting::query()->whereIn('item_id', $items->keys())->where('tracking', ItemSetting::SERIAL)->pluck('item_id')->all();
        $lines = [];

        foreach ($order['lines'] as $line) {
            if (! $items->has($line['item_id'])) {
                continue;
            }

            $lines[] = new PostingLine(
                $line['item_id'], $order['location_id'], Movement::SALE, Qty::of($line['qty_base'])->negated(),
                sourceLineId: $line['line_id'], uomId: $line['uom_id'], uomQty: Qty::of($line['qty']),
                flags: in_array($line['item_id'], $serial, true) ? [['code' => 'serial_not_captured']] : [], fefo: true,
            );
        }

        $this->ledger->post(new Posting($order['company_id'], 'ecommerce.order', $order['order_id'], CarbonImmutable::parse($order['at']), $order['user_id'], $lines, manual: false, reference: $order['number']));
    }

    /** Under the order's lock: what each of the order's movements took, back at its cost. */
    private function back(array $order): void
    {
        $out = Movement::query()->where('source_type', 'ecommerce.order')->where('source_id', $order['order_id'])->where('kind', Movement::SALE)
            ->orderBy('created_at')->orderBy('id')->get();

        if ($out->isEmpty()) {
            if (Movement::query()->where('source_type', 'ecommerce.return')->where('source_id', $order['order_id'])->exists()) {
                return;
            }

            // The hand-over is not posted yet (jobs out of order): the job tries again.
            throw new RuntimeException('inventory: the online order of this return is not posted yet');
        }

        $lines = [];

        foreach ($out as $movement) {
            $lines[] = new PostingLine(
                $movement->item_id, $movement->location_id, Movement::SALE_RETURN, Qty::of((string) $movement->qty)->abs(),
                sourceLineId: $movement->source_line_id, batchId: $movement->batch_id, uomId: $movement->uom_id,
                uomQty: $movement->uom_qty === null ? null : BigDecimal::of((string) $movement->uom_qty)->abs(),
                cost: BigInteger::of((string) $movement->value_minor)->abs(), reversesMovementId: $movement->id,
            );
        }

        $this->ledger->post(new Posting($order['company_id'], 'ecommerce.return', $order['order_id'], CarbonImmutable::parse($order['at']), $order['user_id'], $lines, manual: false, reference: $order['number']));
    }
}
