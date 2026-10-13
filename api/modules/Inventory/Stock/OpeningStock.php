<?php

namespace Modules\Inventory\Stock;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Movement;

/**
 * INV-18: opening stock, one line at a time (the API) or per imported row
 * (IMP-01): a receipt (`opening`) at a typed unit cost, with its batch
 * and serials, audited as `inventory.opening.create` before the stock
 * moves (the ledger's lock order).
 *
 * reverse(): an imported line undone (IMP-02), exactly (its own cost and
 * layer), refused once anything later moved the item in the company
 * (`opening_used`) or when it covered a shortfall (`opening_has_variance`),
 * checked again under the item's cost lock (StockLedger::assertReversible).
 */
class OpeningStock
{
    public function __construct(
        private readonly StockInput $input,
        private readonly StockLedger $ledger,
        private readonly Places $places,
        private readonly Valuer $valuer,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Check a line without saving (an import's preview and dry run).
     *
     * @param  array<string, mixed>  $data
     */
    public function check(array $data): ResolvedLine
    {
        $place = $this->places->of((string) $data['location_id']) ?? throw StockLedger::error(422, 'location_unknown', field: 'location_id');

        if ($place['archived']) {
            throw StockLedger::error(422, 'location_archived', field: 'location_id');
        }

        return $this->input->single($data, fn () => true, (string) $data['location_id']);
    }

    /** @param array{item_id: string, location_id: string, uom_id?: ?string, qty: string, unit_cost: string, batch_number?: ?string, expires_on?: ?string, serials?: list<string>} $data */
    public function record(array $data, ?User $by): Movement
    {
        $line = $this->check($data);
        $place = $this->places->of($data['location_id']);
        $currency = $this->valuer->currency($place['company_id']);
        $cost = $this->valuer->typedCost((string) $data['unit_cost'], $line->qty, $currency);

        return StockInput::transaction(function () use ($data, $by, $line, $place, $cost) {
            $sourceId = (string) Str::uuid7();
            $this->auditor->record('inventory.opening.create', $line->item, null, [
                'opening_id' => $sourceId,
                'location_id' => $data['location_id'],
                'uom_id' => $line->uomId,
                'qty' => Qty::show($line->qty),
                'unit_cost' => (string) $data['unit_cost'],
                'batch_number' => $line->batchNumber,
                'serials' => $line->serials,
            ]);

            [$movement] = $this->ledger->post(new Posting($place['company_id'], 'inventory.opening', $sourceId, CarbonImmutable::now(), $by?->id, [
                new PostingLine(
                    $line->item->id, $data['location_id'], Movement::OPENING, $line->qtyBase,
                    batchId: $this->input->batchFor($line),
                    uomId: $line->uomId,
                    uomQty: $line->qty,
                    cost: $cost,
                    serialIds: $this->input->serialIds($line->item->id, $line->serials, true, $data['location_id']),
                    setsLastCost: true,
                ),
            ]));

            return $movement;
        });
    }

    /** Whether anything moved $movement's item in its company after it. */
    public function used(Movement $movement): bool
    {
        return Movement::query()->where('company_id', $movement->company_id)->where('item_id', $movement->item_id)
            ->where('id', '<>', $movement->id)
            ->where('created_at', '>=', $movement->created_at)
            ->where(fn ($q) => $q->where('created_at', '>', $movement->created_at)->orWhere('id', '>', $movement->id))
            ->exists();
    }

    public function reverse(Movement $movement, ?User $by): void
    {
        StockInput::transaction(function () use ($movement, $by) {
            if (Movement::query()->where('reverses_movement_id', $movement->id)->exists()) {
                return;
            }

            StockLedger::assertReversible($movement);

            $this->auditor->record('inventory.opening.reverse', $movement->item, null, ['opening_id' => $movement->source_id, 'movement_id' => $movement->id]);

            $this->ledger->post(new Posting($movement->company_id, 'inventory.opening', $movement->source_id, CarbonImmutable::now(), $by?->id, [
                new PostingLine(
                    $movement->item_id, $movement->location_id, Movement::OPENING_REVERSAL, Qty::of((string) $movement->qty)->negated(),
                    batchId: $movement->batch_id,
                    reversesMovementId: $movement->id,
                    serialIds: $movement->serials()->pluck('inventory_serials.id')->all(),
                ),
            ]));
        });
    }
}
