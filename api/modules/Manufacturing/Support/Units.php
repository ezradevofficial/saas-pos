<?php

namespace Modules\Manufacturing\Support;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use Brick\Math\BigDecimal;

/**
 * MD-02, MFG-04: an item's units through core item units: the base unit
 * holds 1, others their factor (base units per unit). Typed quantities
 * follow the unit's precision: whole numbers for counted units, 3 places
 * for the rest (as Inventory and Stores).
 */
class Units
{
    /** @var array<string, ?string> */
    private array $kinds = [];

    /** The base units one $uomId holds for $item, or null when the item has no such unit. */
    public function factor(Item $item, ?string $uomId): ?BigDecimal
    {
        if ($uomId === null || $uomId === $item->base_uom_id) {
            return BigDecimal::one();
        }

        $factor = ItemUom::query()->where('item_id', $item->id)->where('uom_id', $uomId)->value('factor');

        return $factor === null ? null : BigDecimal::of((string) $factor);
    }

    public function fitsPrecision(string $uomId, BigDecimal $qty): bool
    {
        $kind = $this->kinds[$uomId] ??= Uom::query()->whereKey($uomId)->value('kind');

        return $qty->strippedOfTrailingZeros()->getScale() <= ($kind === 'count' ? 0 : 3);
    }

    /**
     * $qty of a stored line's unit in the base unit by the line's own
     * factor (`$lineBase / $lineQty`), so a unit factor changed later never
     * changes what the line meant; the whole line gives its base exactly.
     */
    public static function baseOf(BigDecimal|string $qty, BigDecimal|string $lineQty, BigDecimal|string $lineBase): BigDecimal
    {
        $qty = BigDecimal::of($qty);

        if ($qty->isEqualTo($lineQty)) {
            return Qty::of($lineBase);
        }

        return Qty::of(Qty::ratio($qty, $lineBase, $lineQty));
    }
}
