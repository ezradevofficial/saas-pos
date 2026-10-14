<?php

namespace Modules\Stores\Stock;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Stores\Models\Movement;

/**
 * STR-07, STR-08: the only writer of `stores_movements`.
 *
 * - current(): which ledger holds a store's quantities now: Inventory's
 *   when the tenant has Inventory active (RBAC-08), else Stores' own.
 * - post(): one source document's movements in one transaction, once per
 *   source (an advisory lock, then a look for rows already there: a source
 *   posted twice moves nothing more). In the own ledger each store and
 *   item is locked (in a fixed order, so postings never deadlock), its
 *   balance read from its latest movement, and a movement out that would
 *   leave it below zero is refused (`insufficient_stock`) when asked. In
 *   the Inventory ledger the rows only record the document (no balance).
 * - value(): STR-09: writes Inventory's value into the source's movements
 *   that have none (the only update the table allows).
 */
class StoreLedger
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function current(): string
    {
        return $this->modules->isActive('inventory') ? Movement::LEDGER_INVENTORY : Movement::LEDGER_STORES;
    }

    /**
     * @param  list<array{item_id: string, location_id: string, kind: string, qty: BigDecimal, uom_id?: ?string, uom_qty?: ?BigDecimal, source_line_id?: ?string}>  $lines  `qty` signed, in the base unit
     * @param  array{reference?: ?string, department_id?: ?string, cost_centre_id?: ?string, reason?: ?string, note?: ?string}  $context
     * @return list<Movement>
     */
    public function post(string $ledger, string $companyId, string $sourceType, string $sourceId, CarbonImmutable $at, ?string $userId, array $lines, array $context = [], bool $refuseNegative = true): array
    {
        if ($lines === []) {
            return [];
        }

        return Errors::transaction(function () use ($ledger, $companyId, $sourceType, $sourceId, $at, $userId, $lines, $context, $refuseNegative) {
            $connection = DB::connection(TenantContext::CONNECTION);
            $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['stores:'.$sourceType.':'.$sourceId]);
            $existing = Movement::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->orderBy('seq')->get();

            if ($existing->isNotEmpty()) {
                return $existing->all();
            }

            $own = $ledger === Movement::LEDGER_STORES;

            if ($own) {
                $places = array_unique(array_map(fn (array $line) => $line['location_id'].':'.$line['item_id'], $lines));
                sort($places);

                foreach ($places as $place) {
                    $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['stores:balance:'.$place]);
                }
            }

            $movements = [];

            foreach ($lines as $line) {
                $after = null;

                if ($own) {
                    $after = Qty::of(self::balance($line['location_id'], $line['item_id'])->plus($line['qty']));

                    if ($refuseNegative && $line['qty']->isNegative() && $after->isNegative()) {
                        throw Errors::make(422, 'insufficient_stock', ['item' => (string) Item::query()->whereKey($line['item_id'])->value('name')]);
                    }
                }

                $movements[] = Movement::create([
                    'company_id' => $companyId,
                    'location_id' => $line['location_id'],
                    'item_id' => $line['item_id'],
                    'kind' => $line['kind'],
                    'ledger' => $ledger,
                    'qty' => (string) Qty::of($line['qty']),
                    'uom_id' => $line['uom_id'] ?? null,
                    'uom_qty' => isset($line['uom_qty']) ? (string) Qty::of($line['uom_qty']) : null,
                    'balance_after' => $after === null ? null : (string) $after,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'source_line_id' => $line['source_line_id'] ?? null,
                    'reference' => $context['reference'] ?? null,
                    'department_id' => $context['department_id'] ?? null,
                    'cost_centre_id' => $context['cost_centre_id'] ?? null,
                    'reason' => $context['reason'] ?? null,
                    'note' => $context['note'] ?? null,
                    'occurred_at' => $at,
                    'created_by' => $userId,
                ]);
            }

            return $movements;
        });
    }

    /** The store's quantity of an item in the own ledger (its latest movement's balance). */
    public static function balance(string $locationId, string $itemId): BigDecimal
    {
        $balance = Movement::query()->where('location_id', $locationId)->where('item_id', $itemId)
            ->where('ledger', Movement::LEDGER_STORES)->orderByDesc('seq')->value('balance_after');

        return $balance === null ? Qty::zero() : Qty::of((string) $balance);
    }

    /**
     * STR-09: Inventory's values of a source's lines (signed minor units per
     * line id), written once.
     *
     * @param  array<string, string>  $values
     */
    public function value(string $sourceType, string $sourceId, array $values, string $currency): void
    {
        foreach ($values as $lineId => $value) {
            Movement::query()->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('source_line_id', $lineId)->whereNull('value_minor')
                ->update(['value_minor' => $value, 'currency' => $currency]);
        }
    }
}
