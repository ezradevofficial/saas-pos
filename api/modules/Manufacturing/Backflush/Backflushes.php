<?php

namespace Modules\Manufacturing\Backflush;

use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Modules\Manufacturing\Boms\Explosion;
use Modules\Manufacturing\Boms\Versions;
use Modules\Manufacturing\Events\MaterialsMoved;
use Modules\Manufacturing\ManufacturingServiceProvider;
use Modules\Manufacturing\Models\Backflush;
use Modules\Manufacturing\Models\BackflushLine;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Support\Errors;
use Modules\Manufacturing\Support\Places;
use Modules\Manufacturing\Support\Qty;
use Modules\Manufacturing\Support\Units;
use Modules\POS\Events\SaleStockLines;

/**
 * MFG-07 to MFG-09: restaurant backflush, in the current tenant, only
 * while Manufacturing and Inventory are active (POS raised the sale). Sale
 * lines come through POS's SaleStockLines (Manufacturing never reads POS
 * tables); everything about one sale runs under one advisory lock.
 *
 * - sale(): a completed sale not backflushed yet: each line whose item is
 *   not a stock item (made to order, decision 3) and has a recipe in force
 *   at the sale's time consumes its exploded ingredients for the sold
 *   quantity (base unit); a stock item with a recipe is skipped
 *   (`item_stocked`), as are lines whose unit or recipe cannot be used.
 *   Then refunds already applied are given back (each its own record).
 *   A voided sale consumes nothing.
 * - void(): everything the sale consumed and not yet returned comes back.
 * - refund(): each refunded line's share of its ingredients comes back:
 *   the cumulative return after this refund is consumed × refunded ÷ sold
 *   (6 places, half up), less what came back before, so the refund that
 *   completes a line returns exactly what is left.
 *
 * Each record is unique per source (pos.sale, pos.void, pos.refund): an
 * event handled twice changes nothing. A void or refund handled before its
 * sale finds no consumption and does nothing; the sale, handled later,
 * reads the sale's state. MaterialsMoved (after commit) has Inventory
 * move the stock.
 */
class Backflushes
{
    public const SALE = 'sale';

    public const VOID = 'void';

    public const REFUND = 'refund';

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly TenantContext $tenants,
        private readonly Versions $versions,
        private readonly Explosion $explosion,
        private readonly Units $units,
        private readonly Places $places,
    ) {}

    /** Whether recipes backflush for the current tenant now (MFG-07). */
    public function active(): bool
    {
        return $this->modules->isActive(ManufacturingServiceProvider::MODULE) && $this->modules->isActive('inventory');
    }

    public function run(string $kind, string $id): void
    {
        if (! $this->active()) {
            return;
        }

        match ($kind) {
            self::SALE => $this->sale($id),
            self::VOID => $this->void($id),
            default => $this->refund($id),
        };
    }

    private function sale(string $saleId): void
    {
        Errors::transaction(function () use ($saleId) {
            $this->lock($saleId);
            $sale = SaleStockLines::forSale($saleId);

            if ($sale === null || $sale['status'] !== 'completed') {
                return;
            }

            if (! $this->exists(Backflush::SALE, $saleId)) {
                $this->consume($sale);
            }

            foreach ($sale['refund_ids'] as $refundId) {
                $refund = SaleStockLines::forRefund($refundId);

                if ($refund !== null) {
                    $this->giveBack($refund, Backflush::REFUND, $refundId, $sale);
                }
            }
        });
    }

    private function void(string $voidId): void
    {
        $void = SaleStockLines::forVoid($voidId);

        if ($void === null) {
            return;
        }

        Errors::transaction(function () use ($void, $voidId) {
            $this->lock($void['sale_id']);
            $consumed = Backflush::query()->where('source_type', Backflush::SALE)->where('source_id', $void['sale_id'])->first();

            if ($consumed === null || $this->exists(Backflush::VOID, $voidId)) {
                return;
            }

            $lines = [];

            foreach (BackflushLine::query()->where('backflush_id', $consumed->id)->orderBy('line_no')->lockForUpdate()->get() as $line) {
                $left = BigDecimal::of((string) $line->qty_base)->minus((string) $line->qty_returned_base);

                if ($left->isPositive()) {
                    $lines[] = ['origin' => $line, 'qty_base' => Qty::of($left), 'sale_qty' => '0'];
                }
            }

            $this->record(Backflush::VOID, $voidId, $consumed, $void['occurred_at'], $void['receipt_number'] ?? null, $lines);
        });
    }

    private function refund(string $refundId): void
    {
        $refund = SaleStockLines::forRefund($refundId);

        if ($refund === null) {
            return;
        }

        Errors::transaction(function () use ($refund, $refundId) {
            $this->lock($refund['sale_id']);
            $sale = SaleStockLines::forSale($refund['sale_id']);

            if ($sale !== null) {
                $this->giveBack($refund, Backflush::REFUND, $refundId, $sale);
            }
        });
    }

    /** @param array<string, mixed> $sale SaleStockLines::forSale */
    private function consume(array $sale): void
    {
        $at = CarbonImmutable::parse($sale['occurred_at']);
        $items = Item::query()->whereKey(array_column($sale['lines'], 'item_id'))->get()->keyBy('id');
        $lines = [];
        $skipped = [];

        foreach ($sale['lines'] as $line) {
            $item = $items->get($line['item_id']);
            $version = $item === null ? null : $this->versions->inForce($sale['company_id'], $item->id, Bom::RECIPE, $at);

            if ($item === null || $version === null || BigDecimal::of($line['qty'])->isZero()) {
                continue;
            }

            if ($item->type === 'stock') {
                // Decision 3: a stock item is Inventory's to move; its recipe is not applied.
                $skipped[] = ['sale_line_id' => $line['sale_line_id'], 'item_id' => $item->id, 'code' => 'item_stocked'];

                continue;
            }

            $factor = $this->units->factor($item, $line['uom_id']);

            if ($factor === null) {
                $skipped[] = ['sale_line_id' => $line['sale_line_id'], 'item_id' => $item->id, 'code' => 'uom_not_on_item'];

                continue;
            }

            try {
                $exploded = $this->explosion->explode($version, BigDecimal::of($line['qty'])->multipliedBy($factor), $at);
            } catch (ApiException $e) {
                $skipped[] = ['sale_line_id' => $line['sale_line_id'], 'item_id' => $item->id, 'code' => $e->errorCode];

                continue;
            }

            foreach ($exploded['leaves'] as $leaf) {
                if ($leaf['qty_base']->isPositive()) {
                    $lines[] = ['sale_line_id' => $line['sale_line_id'], 'sold_item_id' => $item->id, 'bom_version_id' => $version->id,
                        'item_id' => $leaf['item_id'], 'qty_base' => $leaf['qty_base'], 'sale_qty' => (string) Qty::of($line['qty'])];
                }
            }
        }

        if ($lines === [] && $skipped === []) {
            return;
        }

        $place = $this->places->of($sale['location_id']);
        $backflush = Backflush::create([
            'source_type' => Backflush::SALE, 'source_id' => $sale['sale_id'], 'sale_id' => $sale['sale_id'], 'kind' => Backflush::CONSUME,
            'company_id' => $sale['company_id'], 'branch_id' => $place['branch_id'], 'location_id' => $sale['location_id'],
            'reference' => $sale['receipt_number'] ?? null, 'occurred_at' => $at, 'skipped' => $skipped === [] ? null : $skipped,
        ]);
        $uoms = Item::query()->whereKey(array_column($lines, 'item_id'))->pluck('base_uom_id', 'id');
        $event = [];

        foreach ($lines as $index => $line) {
            $row = BackflushLine::create([
                'backflush_id' => $backflush->id, 'line_no' => $index + 1, 'sale_line_id' => $line['sale_line_id'], 'sold_item_id' => $line['sold_item_id'],
                'bom_version_id' => $line['bom_version_id'], 'item_id' => $line['item_id'], 'uom_id' => $uoms[$line['item_id']],
                'qty_base' => (string) $line['qty_base'], 'sale_qty' => $line['sale_qty'],
            ]);
            $event[] = ['line_id' => $row->id, 'item_id' => $row->item_id, 'uom_id' => $row->uom_id, 'qty' => Qty::show($row->qty_base), 'qty_base' => Qty::show($row->qty_base)];
        }

        if ($event !== []) {
            MaterialsMoved::dispatch($this->tenants->require(), MaterialsMoved::BACKFLUSH, $backflush->id, $backflush->reference, $backflush->company_id,
                $backflush->branch_id, $backflush->location_id, $at->toIso8601String(), null, $event);
        }
    }

    /**
     * Under the sale's lock: a refund's share of each refunded line's ingredients back.
     *
     * @param  array<string, mixed>  $refund  SaleStockLines::forRefund
     * @param  array<string, mixed>  $sale  SaleStockLines::forSale
     */
    private function giveBack(array $refund, string $sourceType, string $sourceId, array $sale): void
    {
        $consumed = Backflush::query()->where('source_type', Backflush::SALE)->where('source_id', $sale['sale_id'])->first();

        if ($consumed === null || $this->exists($sourceType, $sourceId)) {
            return;
        }

        $refunded = [];

        foreach ($refund['lines'] as $line) {
            $refunded[$line['sale_line_id']] = BigDecimal::of($refunded[$line['sale_line_id']] ?? '0')->plus($line['qty']);
        }

        $lines = [];

        foreach (BackflushLine::query()->where('backflush_id', $consumed->id)->whereIn('sale_line_id', array_keys($refunded))->orderBy('line_no')->lockForUpdate()->get() as $origin) {
            $sold = BigDecimal::of((string) $origin->sale_qty);

            if (! $sold->isPositive()) {
                continue;
            }

            // What earlier refunds of this sale line gave back, in the sale line's unit.
            $before = BigDecimal::of((string) (BackflushLine::query()->where('origin_line_id', $origin->id)
                ->whereIn('backflush_id', Backflush::query()->select('id')->where('source_type', Backflush::REFUND))->sum('sale_qty') ?: '0'));
            $after = BigDecimal::min($sold, $before->plus($refunded[$origin->sale_line_id]));
            $target = $after->isEqualTo($sold) ? BigDecimal::of((string) $origin->qty_base) : Qty::of(Qty::ratio((string) $origin->qty_base, $after, $sold));
            $back = Qty::of(BigDecimal::min($target->minus((string) $origin->qty_returned_base), BigDecimal::of((string) $origin->qty_base)->minus((string) $origin->qty_returned_base)));

            if ($back->isPositive()) {
                $lines[] = ['origin' => $origin, 'qty_base' => $back, 'sale_qty' => (string) Qty::of($after->minus($before))];
            }
        }

        $this->record($sourceType, $sourceId, $consumed, $refund['occurred_at'], $refund['receipt_number'] ?? null, $lines);
    }

    /** @param list<array{origin: BackflushLine, qty_base: BigDecimal, sale_qty: string}> $lines */
    private function record(string $sourceType, string $sourceId, Backflush $consumed, string $at, ?string $reference, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $backflush = Backflush::create([
            'source_type' => $sourceType, 'source_id' => $sourceId, 'sale_id' => $consumed->sale_id, 'kind' => Backflush::RETURN,
            'company_id' => $consumed->company_id, 'branch_id' => $consumed->branch_id, 'location_id' => $consumed->location_id,
            'reference' => $reference, 'occurred_at' => CarbonImmutable::parse($at),
        ]);
        $event = [];

        foreach ($lines as $index => $line) {
            $origin = $line['origin'];
            $row = BackflushLine::create([
                'backflush_id' => $backflush->id, 'line_no' => $index + 1, 'sale_line_id' => $origin->sale_line_id, 'sold_item_id' => $origin->sold_item_id,
                'bom_version_id' => $origin->bom_version_id, 'item_id' => $origin->item_id, 'uom_id' => $origin->uom_id,
                'qty_base' => (string) $line['qty_base'], 'sale_qty' => $line['sale_qty'], 'origin_line_id' => $origin->id,
            ]);
            $origin->update(['qty_returned_base' => (string) Qty::of(BigDecimal::of((string) $origin->qty_returned_base)->plus($line['qty_base']))]);
            $event[] = ['line_id' => $row->id, 'item_id' => $row->item_id, 'uom_id' => $row->uom_id, 'qty' => Qty::show($row->qty_base),
                'qty_base' => Qty::show($row->qty_base), 'origin_line_id' => $origin->id];
        }

        MaterialsMoved::dispatch($this->tenants->require(), MaterialsMoved::BACKFLUSH_RETURN, $backflush->id, $backflush->reference, $backflush->company_id,
            $backflush->branch_id, $backflush->location_id, $backflush->occurred_at->toIso8601String(), null, $event, $consumed->id);
    }

    private function exists(string $sourceType, string $sourceId): bool
    {
        return Backflush::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->exists();
    }

    /** One lock per sale: its consumption, void and refunds never interleave. */
    private function lock(string $saleId): void
    {
        Errors::lock('manufacturing:pos-sale:'.$saleId);
    }
}
