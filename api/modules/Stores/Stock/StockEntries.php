<?php

namespace Modules\Stores\Stock;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Stores\Models\Movement;

/**
 * STR-07: stock into the store's own ledger: opening stock (positive
 * lines) and adjustments (signed lines, with a reason; going below zero is
 * refused; a decrease needs `stores.stock.approve` at the store). Only while the own ledger holds the store's quantities
 * (`inventory_active` otherwise: Inventory's opening stock and
 * adjustments apply). Audited as `stores.stock.adjust` (STR-17).
 */
class StockEntries
{
    public const OPENING = 'opening';

    public const ADJUSTMENT = 'adjustment';

    public const REASONS = ['count', 'damage', 'expiry', 'found', 'correction', 'other'];

    public function __construct(
        private readonly Places $places,
        private readonly Lines $lines,
        private readonly StoreLedger $ledger,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{location_id: string, kind: string, reason?: ?string, note?: ?string, lines: list<array<string, mixed>>}  $data
     * @return list<Movement>
     */
    public function record(array $data, User $by): array
    {
        if ($this->ledger->current() !== Movement::LEDGER_STORES) {
            throw Errors::make(409, 'inventory_active');
        }

        $place = $this->places->store($data['location_id']);
        $opening = $data['kind'] === self::OPENING;

        if (! $opening) {
            StoreLedger::assertOpened($data['location_id']);
        }
        $lines = $this->lines->resolve($data['lines'], $place['company_id'], $by, signed: ! $opening);

        // Stage 7B closing review (stores.md decision 12): stock leaves the own ledger by an adjustment only
        // with `stores.stock.approve` at the store; a storekeeper may add stock, not take it out alone.
        $decrease = ! $opening && collect($lines)->contains(fn (array $line) => $line['qty']->isNegative());

        if ($decrease && ! app(ScopeResolver::class)->can($by, 'stores.stock.approve', Scope::location($data['location_id']))) {
            throw Errors::make(403, 'stock_decrease_needs_approval');
        }
        $sourceId = (string) Str::uuid7();

        return Errors::transaction(function () use ($data, $by, $place, $opening, $lines, $sourceId) {
            // Lock order: the audit head before the balances (as issues and returns take them).
            $this->auditor->record('stores.stock.adjust', null, null, [
                'entry' => $sourceId,
                'location_id' => $data['location_id'],
                'kind' => $data['kind'],
                'reason' => $data['reason'] ?? null,
                'lines' => array_map(fn (array $line) => ['item_id' => $line['item']->id, 'uom_id' => $line['uom_id'], 'qty' => Qty::show($line['qty'])], $lines),
            ]);

            return $this->ledger->post(Movement::LEDGER_STORES, $place['company_id'], Movement::SOURCE_ENTRY, $sourceId, CarbonImmutable::now(), $by->id, array_map(fn (array $line) => [
                'item_id' => $line['item']->id,
                'location_id' => $data['location_id'],
                'kind' => $opening ? Movement::OPENING : ($line['qty']->isNegative() ? Movement::ADJUSTMENT_OUT : Movement::ADJUSTMENT_IN),
                'qty' => $line['qty']->isNegative() ? $line['qty_base']->negated() : $line['qty_base'],
                'uom_id' => $line['uom_id'],
                'uom_qty' => $line['qty']->abs(),
                'source_line_id' => (string) Str::uuid7(),
            ], $lines), ['reason' => $opening ? null : ($data['reason'] ?? null), 'note' => $data['note'] ?? null]);
        });
    }
}
