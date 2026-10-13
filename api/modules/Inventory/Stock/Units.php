<?php

namespace Modules\Inventory\Stock;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use Brick\Math\BigDecimal;

/**
 * INV-03: units and conversions, built on the core item units (MD-02):
 * the base unit has factor 1; another unit of the item holds `factor` base
 * units. A unit the item does not have has no factor (`uom_not_on_item`).
 *
 * Typed quantities follow the unit's precision: core units carry none, so
 * it follows the unit's kind (whole numbers for counted units, 3 decimals
 * for weight, volume, length and time). A till's sale is never refused for
 * it (the device wins).
 */
class Units
{
    /** Decimals a typed quantity may have in a unit of this kind. */
    public static function decimals(?string $kind): int
    {
        return $kind === 'count' ? 0 : 3;
    }

    /** The base units one $uomId holds for $item (1 for its base unit), or null. */
    public function factor(Item $item, ?string $uomId): ?BigDecimal
    {
        if ($uomId === null || $uomId === $item->base_uom_id) {
            return BigDecimal::one();
        }

        $factor = ItemUom::query()->where('item_id', $item->id)->where('uom_id', $uomId)->value('factor');

        return $factor === null ? null : BigDecimal::of((string) $factor);
    }

    public function toBase(BigDecimal $qty, BigDecimal $factor): BigDecimal
    {
        return Qty::of($qty->multipliedBy($factor));
    }

    /** Whether $qty fits the precision of $uomId (a count unit takes whole numbers). */
    public function fitsPrecision(string $uomId, BigDecimal $qty): bool
    {
        $kind = Uom::query()->whereKey($uomId)->value('kind');

        return $qty->strippedOfTrailingZeros()->getScale() <= self::decimals($kind);
    }
}
