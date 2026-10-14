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
use Modules\Inventory\Stock\Places;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Modules\Stores\Events\StoreIssued;
use Modules\Stores\Events\StoreReturned;
use RuntimeException;

/**
 * STR-08 (docs/modules/stores.md): Stores' issues and returns move stock
 * when Inventory is active for the tenant (RBAC-08) and the document went
 * through Inventory's ledger (`ledger = inventory`); with Inventory off,
 * Stores keeps the quantities itself and nothing happens here. Queued
 * after the Stores document commits, with the event's own lines (Inventory
 * never reads Stores' tables).
 *
 * - StoreIssued: each stock item's base quantity leaves the store
 *   (`store_issue`), batches taken earliest expiry first; never refused (a
 *   shortfall is flagged `negative_stock`, INV-13); serial items are
 *   flagged `serial_not_captured`.
 * - StoreReturned: the returned base quantity comes back (`store_return`)
 *   from the issue line's movements, in order, at their cost and into
 *   their batches; the last units take what is left of the value exactly.
 *   A return handled before its issue is retried by its job.
 *
 * Both run under one advisory lock per issue. The ledger posts each source
 * once, so a redelivered event moves nothing (STR-08). StockMoved carries
 * the values back to Stores (STR-09).
 */
class MoveStockForStores
{
    public const ISSUE = 'issue';

    public const RETURN = 'return';

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly StockLedger $ledger,
        private readonly Places $places,
    ) {}

    public function handle(StoreIssued|StoreReturned $event): void
    {
        if ($event->ledger !== 'inventory') {
            return;
        }

        $event instanceof StoreIssued
            ? MoveStoreStock::dispatch($event->tenantId, self::ISSUE, [
                'id' => $event->issueId, 'issue_id' => $event->issueId, 'company_id' => $event->companyId, 'location_id' => $event->locationId,
                'at' => $event->issuedAt, 'user_id' => $event->userId, 'lines' => $event->lines,
            ])
            : MoveStoreStock::dispatch($event->tenantId, self::RETURN, [
                'id' => $event->returnId, 'issue_id' => $event->issueId, 'company_id' => $event->companyId, 'location_id' => $event->locationId,
                'at' => $event->returnedAt, 'user_id' => $event->userId, 'lines' => $event->lines,
            ]);
    }

    /**
     * Move the stock of one issue or return ($kind), in the current tenant.
     *
     * @param  array{id: string, issue_id: string, company_id: string, location_id: string, at: string, user_id: ?string, lines: list<array<string, string>>}  $document
     */
    public function move(string $kind, array $document): void
    {
        if (! $this->modules->isActive(InventoryServiceProvider::MODULE)) {
            return;
        }

        $place = $this->places->of($document['location_id']);

        if ($place === null || $place['company_id'] !== $document['company_id']) {
            return;
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($kind, $document) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['inventory:stores-issue:'.$document['issue_id']]);

            $kind === self::ISSUE ? $this->issue($document) : $this->back($document);
        });
    }

    private function issue(array $document): void
    {
        $items = Item::query()->whereKey(array_column($document['lines'], 'item_id'))->where('type', 'stock')->get()->keyBy('id');
        $serial = ItemSetting::query()->whereIn('item_id', $items->keys())->where('tracking', ItemSetting::SERIAL)->pluck('item_id')->all();
        $lines = [];

        foreach ($document['lines'] as $line) {
            if (! $items->has($line['item_id'])) {
                continue;
            }

            $lines[] = new PostingLine(
                $line['item_id'], $document['location_id'], Movement::STORE_ISSUE, Qty::of($line['qty_base'])->negated(),
                sourceLineId: $line['line_id'], uomId: $line['uom_id'], uomQty: Qty::of($line['qty']),
                flags: in_array($line['item_id'], $serial, true) ? [['code' => 'serial_not_captured']] : [], fefo: true,
            );
        }

        $this->ledger->post(new Posting($document['company_id'], 'stores.issue', $document['id'], CarbonImmutable::parse($document['at']), $document['user_id'], $lines, manual: false));
    }

    /** Under the issue's lock: from the movements of each issue line, what they still hold back, at their cost. */
    private function back(array $document): void
    {
        $issued = Movement::query()->where('source_type', 'stores.issue')->where('source_id', $document['issue_id'])->where('kind', Movement::STORE_ISSUE)
            ->orderBy('created_at')->orderBy('id')->get()->groupBy('source_line_id');

        if ($issued->isEmpty() && Movement::query()->where('source_type', 'stores.return')->where('source_id', $document['id'])->doesntExist()) {
            // Its issue is not posted yet (jobs out of order): the job tries again.
            throw new RuntimeException('inventory: the store issue of this return is not posted yet');
        }

        $lines = [];

        foreach ($document['lines'] as $line) {
            $left = Qty::of($line['qty_base']);

            foreach ($issued[$line['issue_line_id']] ?? [] as $out) {
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

                // The last units take what is left of the value, exactly.
                $cost = $take->isEqualTo($remaining) ? $outValue->minus($returnedValue) : Qty::share($outValue, $take, $outQty);

                $lines[] = new PostingLine(
                    $out->item_id, $out->location_id, Movement::STORE_RETURN, $take,
                    sourceLineId: $line['line_id'], batchId: $out->batch_id, uomId: $line['uom_id'], uomQty: BigDecimal::of($line['qty']),
                    cost: $cost, reversesMovementId: $out->id,
                );
                $left = Qty::of($left->minus($take));
            }
        }

        $this->ledger->post(new Posting($document['company_id'], 'stores.return', $document['id'], CarbonImmutable::parse($document['at']), $document['user_id'], $lines, manual: false));
    }
}
