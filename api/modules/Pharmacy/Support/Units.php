<?php

namespace Modules\Pharmacy\Support;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use Brick\Math\BigDecimal;

/**
 * Units and conversions, built on the core item units (MD-02): the base
 * unit has factor 1; another unit of the item holds `factor` base units.
 * A unit the item does not have has no factor (`uom_not_on_item`).
 */
class Units
{
    /** The base units one $uomId holds for $item (1 for its base unit), or null. */
    public function factor(Item $item, ?string $uomId): ?BigDecimal
    {
        if ($uomId === null || $uomId === $item->base_uom_id) {
            return BigDecimal::one();
        }

        $factor = ItemUom::query()->where('item_id', $item->id)->where('uom_id', $uomId)->value('factor');

        return $factor === null ? null : BigDecimal::of((string) $factor);
    }

    /** $qty of $uomId in the item's base unit; a unit the item lacks is treated as the base unit (a till's sale is never refused for it). */
    public function toBase(Item $item, ?string $uomId, string $qty): BigDecimal
    {
        return Qty::of(BigDecimal::of($qty)->multipliedBy($this->factor($item, $uomId) ?? BigDecimal::one()));
    }
}
