<?php

namespace App\Core\MasterData\Prices;

use App\Core\Currency\Money;
use JsonSerializable;

/**
 * The price PriceResolver found for one unit of an item (MD-03 follow-up).
 *
 * - `money`: the price of one `uomId`, in the list's currency.
 * - `source`: `unit` when a price was set for that unit; `base` when it is
 *   derived from the base unit's price × `factor` (rounded once, half up,
 *   to the currency's minor unit).
 * - `itemPriceId`: the stored price used (the base unit's when derived).
 * - `taxInclusive`: the list's flag, for the tax calculator.
 */
final class ResolvedPrice implements JsonSerializable
{
    public function __construct(
        public readonly Money $money,
        public readonly string $priceListId,
        public readonly bool $taxInclusive,
        public readonly string $itemPriceId,
        public readonly string $uomId,
        public readonly string $source,
        public readonly string $factor,
        public readonly string $effectiveFrom,
        public readonly string $minQuantity,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'amount_minor' => $this->money->minor(),
            'currency' => $this->money->currency(),
            'price_list_id' => $this->priceListId,
            'tax_inclusive' => $this->taxInclusive,
            'item_price_id' => $this->itemPriceId,
            'uom_id' => $this->uomId,
            'source' => $this->source,
            'factor' => $this->factor,
            'effective_from' => $this->effectiveFrom,
            'min_quantity' => $this->minQuantity,
        ];
    }
}
