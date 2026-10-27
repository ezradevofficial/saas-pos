<?php

namespace Modules\VanSales\Support;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use Brick\Math\BigDecimal;

/**
 * VAN-19: quantities in the item's base unit, built on the core item units
 * (MD-02): the base unit has factor 1, another unit of the item holds
 * `factor` base units. A unit the item lacks is treated as the base unit
 * (a till's sale is never refused for it).
 */
class Units
{
    /** @var array<string, string> item id => base unit id */
    private array $bases = [];

    public function toBase(string $itemId, ?string $uomId, string $qty): BigDecimal
    {
        $base = $this->bases[$itemId] ??= (string) Item::query()->whereKey($itemId)->value('base_uom_id');
        $factor = $uomId === null || $uomId === $base
            ? BigDecimal::one()
            : BigDecimal::of((string) (ItemUom::query()->where('item_id', $itemId)->where('uom_id', $uomId)->value('factor') ?? '1'));

        return Qty::of(BigDecimal::of($qty)->multipliedBy($factor));
    }
}
