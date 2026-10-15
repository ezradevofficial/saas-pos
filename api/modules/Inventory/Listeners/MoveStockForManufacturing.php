<?php

namespace Modules\Inventory\Listeners;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Events\StockMoved;
use Modules\Inventory\Models\ItemSetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Places;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Modules\Manufacturing\Events\MaterialsMoved;
use RuntimeException;

/**
 * MFG-15 (docs/modules/manufacturing.md): Manufacturing's documents move
 * stock here. Manufacturing raises MaterialsMoved only for documents whose
 * ledger it fixed as Inventory's (an order released, or a sale
 * backflushed, while Inventory was active), so they post whatever the
 * module's state when the job runs. Queued after the Manufacturing
 * document commits, with the event's own lines (Inventory never reads
 * Manufacturing's tables).
 *
 * - issue, backflush: each stock item's base quantity leaves the location
 *   (`production_issue`, `recipe_consumption`), batches earliest expiry
 *   first; never refused (a shortfall is flagged `negative_stock`, INV-13);
 *   serial items are flagged `serial_not_captured`.
 * - return, backflush_return: the base quantity comes back
 *   (`production_return`, `recipe_return`) from the origin line's
 *   movements, in order, at their cost and into their batches; the last
 *   units take what is left of the value exactly. A return handled before
 *   its origin is retried by its job.
 * - receipt: the costed good output and by-products enter stock
 *   (`production_receipt`) at the line's cost (a cost layer, the item's
 *   last cost).
 *
 * Everything about one origin (an issue or a sale's backflush) runs under
 * one advisory lock. The ledger posts each source once; a redelivered
 * event raises StockMoved again so Manufacturing can record values it
 * missed (its own guard keeps that once).
 */
class MoveStockForManufacturing
{
    /** MaterialsMoved kind => the movement kind it posts. */
    public const KINDS = [
        MaterialsMoved::ISSUE => Movement::PRODUCTION_ISSUE,
        MaterialsMoved::RETURN => Movement::PRODUCTION_RETURN,
        MaterialsMoved::RECEIPT => Movement::PRODUCTION_RECEIPT,
        MaterialsMoved::BACKFLUSH => Movement::RECIPE_CONSUMPTION,
        MaterialsMoved::BACKFLUSH_RETURN => Movement::RECIPE_RETURN,
    ];

    /** A return kind => the kind of the document it gives back. */
    private const ORIGINS = [MaterialsMoved::RETURN => MaterialsMoved::ISSUE, MaterialsMoved::BACKFLUSH_RETURN => MaterialsMoved::BACKFLUSH];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly StockLedger $ledger,
        private readonly Places $places,
    ) {}

    public function handle(MaterialsMoved $event): void
    {
        MoveManufacturingStock::dispatch($event->tenantId, [
            'kind' => $event->kind, 'id' => $event->documentId, 'number' => $event->number, 'company_id' => $event->companyId,
            'location_id' => $event->locationId, 'at' => $event->occurredAt, 'user_id' => $event->userId, 'lines' => $event->lines,
            'origin_id' => $event->originId,
        ]);
    }

    /**
     * Move the stock of one Manufacturing document, in the current tenant.
     *
     * @param  array{kind: string, id: string, number: ?string, company_id: string, location_id: string, at: string, user_id: ?string, lines: list<array<string, ?string>>, origin_id: ?string}  $document
     */
    public function move(array $document): void
    {
        $place = $this->places->of($document['location_id']);

        if ($place === null || $place['company_id'] !== $document['company_id'] || ! isset(self::KINDS[$document['kind']])) {
            return;
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($document) {
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['inventory:manufacturing:'.($document['origin_id'] ?? $document['id'])]);

            match ($document['kind']) {
                MaterialsMoved::ISSUE, MaterialsMoved::BACKFLUSH => $this->out($document),
                MaterialsMoved::RECEIPT => $this->receipt($document),
                default => $this->back($document),
            };
        });
    }

    private function out(array $document): void
    {
        $items = Item::query()->whereKey(array_column($document['lines'], 'item_id'))->where('type', 'stock')->get()->keyBy('id');
        $serial = ItemSetting::query()->whereIn('item_id', $items->keys())->where('tracking', ItemSetting::SERIAL)->pluck('item_id')->all();
        $lines = [];

        foreach ($document['lines'] as $line) {
            if (! $items->has($line['item_id'])) {
                continue;
            }

            $lines[] = new PostingLine(
                $line['item_id'], $document['location_id'], self::KINDS[$document['kind']], Qty::of((string) $line['qty_base'])->negated(),
                sourceLineId: $line['line_id'], uomId: $line['uom_id'], uomQty: Qty::of((string) $line['qty']),
                flags: in_array($line['item_id'], $serial, true) ? [['code' => 'serial_not_captured']] : [], fefo: true,
            );
        }

        $this->post($document, $lines);
    }

    private function receipt(array $document): void
    {
        $items = Item::query()->whereKey(array_column($document['lines'], 'item_id'))->where('type', 'stock')->get()->keyBy('id');
        $lines = [];

        foreach ($document['lines'] as $line) {
            if (! $items->has($line['item_id'])) {
                continue;
            }

            $lines[] = new PostingLine(
                $line['item_id'], $document['location_id'], Movement::PRODUCTION_RECEIPT, Qty::of((string) $line['qty_base']),
                sourceLineId: $line['line_id'], uomId: $line['uom_id'], uomQty: Qty::of((string) $line['qty']),
                cost: BigInteger::of((string) ($line['cost_minor'] ?? '0')), setsLastCost: true,
            );
        }

        $this->post($document, $lines);
    }

    /** From the movements of each origin line, what they still hold back, at their cost. */
    private function back(array $document): void
    {
        $stock = Item::query()->whereKey(array_column($document['lines'], 'item_id'))->where('type', 'stock')->pluck('id')->all();
        $document['lines'] = array_values(array_filter($document['lines'], fn (array $line) => in_array($line['item_id'], $stock, true)));

        if ($document['lines'] === []) {
            $this->post($document, []);

            return;
        }

        $originType = MaterialsMoved::SOURCES[self::ORIGINS[$document['kind']]];
        $outKind = self::KINDS[self::ORIGINS[$document['kind']]];
        $sourceType = MaterialsMoved::SOURCES[$document['kind']];
        $out = Movement::query()->where('source_type', $originType)->where('source_id', $document['origin_id'])->where('kind', $outKind)
            ->orderBy('created_at')->orderBy('id')->get()->groupBy('source_line_id');

        if ($out->isEmpty() && Movement::query()->where('source_type', $sourceType)->where('source_id', $document['id'])->doesntExist()) {
            // Its origin is not posted yet (jobs out of order): the job tries again.
            throw new RuntimeException('inventory: the origin of this manufacturing return is not posted yet');
        }

        $lines = [];

        foreach ($document['lines'] as $line) {
            $left = Qty::of((string) $line['qty_base']);

            foreach ($out[$line['origin_line_id']] ?? [] as $movement) {
                if (! $left->isPositive()) {
                    break;
                }

                $outQty = Qty::of((string) $movement->qty)->abs();
                $outValue = BigInteger::of((string) $movement->value_minor)->abs();
                $returned = Movement::query()->where('reverses_movement_id', $movement->id);
                $returnedQty = Qty::of((string) ((clone $returned)->sum('qty') ?? '0'));
                $returnedValue = BigInteger::of((string) ((clone $returned)->sum('value_minor') ?? '0'));
                $remaining = Qty::of($outQty->minus($returnedQty));
                $take = Qty::min($remaining, $left);

                if (! $take->isPositive()) {
                    continue;
                }

                // The last units take what is left of the value, exactly.
                $cost = $take->isEqualTo($remaining) ? $outValue->minus($returnedValue) : Qty::share($outValue, $take, $outQty);

                $lines[] = new PostingLine(
                    $movement->item_id, $movement->location_id, self::KINDS[$document['kind']], $take,
                    sourceLineId: $line['line_id'], batchId: $movement->batch_id, uomId: $line['uom_id'], uomQty: BigDecimal::of((string) $line['qty']),
                    cost: $cost, reversesMovementId: $movement->id,
                );
                $left = Qty::of($left->minus($take));
            }
        }

        $this->post($document, $lines);
    }

    /**
     * Post, or, when the document was posted before (a redelivered event),
     * raise StockMoved again with its movements, so Manufacturing can still
     * record values it missed.
     *
     * @param  list<PostingLine>  $lines
     */
    private function post(array $document, array $lines): void
    {
        $posting = new Posting($document['company_id'], MaterialsMoved::SOURCES[$document['kind']], $document['id'], CarbonImmutable::parse($document['at']),
            $document['user_id'], $lines, manual: false, reference: $document['number'] ?? null);
        if ($lines === []) {
            // Review fix 1: a redelivered document whose stock already moved (a full return or void
            // posted before: nothing is left to take now) raises its own movements again, so a value
            // Manufacturing missed is recorded at what it really was.
            $posted = Movement::query()->where('source_type', $posting->sourceType)->where('source_id', $posting->sourceId)
                ->orderBy('created_at')->orderBy('id')->get()->all();

            if ($posted !== []) {
                event(StockMoved::of($this->tenants->require(), $posting->companyId, $posted[0]->currency, $posting->sourceType, $posting->sourceId, $posted, $posting->occurredAt));

                return;
            }

            // Nothing Inventory tracks (an item no longer a stock item) and never posted: the document is valued at nothing.
            event(new StockMoved($this->tenants->require(), $posting->companyId, $this->ledger->baseCurrency($posting->companyId), $posting->sourceType,
                $posting->sourceId, [], $posting->occurredAt->utc()->format('Y-m-d\TH:i:s.u\Z'), null, $posting->reference));

            return;
        }

        $before = Movement::query()->where('source_type', $posting->sourceType)->where('source_id', $posting->sourceId)->exists();
        $movements = $this->ledger->post($posting);

        if ($before && $movements !== []) {
            event(StockMoved::of($this->tenants->require(), $posting->companyId, $movements[0]->currency, $posting->sourceType, $posting->sourceId, $movements, $posting->occurredAt));
        }
    }
}
