<?php

namespace App\Core\MasterData\Prices;

use App\Core\Currency\Money;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The price of an item in a unit (MD-03 follow-up), for the POS and any
 * module that sells. Runs in the tenant's context (RLS).
 *
 * Which list: the given one, or the company's active default list in
 * `$currency` (the company's base currency when null). None, or an
 * archived list: no price (null).
 *
 * Which day: `$at` (now by default) as a date in the list's company's time
 * zone; a `Y-m-d` string is taken as that date (anything else throws
 * InvalidArgumentException).
 *
 * Which price, among the list's active prices of the item effective on
 * that day (`effective_from` <= day):
 *
 * 1. The unit's own prices with `min_quantity` <= `$quantity`: the highest
 *    quantity break wins, then the latest start date. Each break keeps its
 *    own history: a new price from 1 unit leaves a break from 10 units as
 *    it was until it is archived.
 * 2. Else, for a unit other than the base unit, the base unit's price for
 *    `$quantity` × factor base units, by the same rule, multiplied by the
 *    unit's factor and rounded once, half up, to the currency's minor unit
 *    (CDF to the franc). An explicit unit price always wins over the
 *    derived one. Cash rounding (CUR-01) is not applied here: it belongs
 *    to the amount tendered (CUR-06).
 * 3. Else null.
 *
 * The item must be active, the list's company's or shared (TEN-08), and the unit
 * its base unit or one of its other units; otherwise null. Prices left
 * from an item's earlier company or removed units are never used.
 */
class PriceResolver
{
    public function priceFor(
        Item $item,
        string $uomId,
        PriceList|Company $from,
        CarbonInterface|string|null $at = null,
        string|int $quantity = '1',
        ?string $currency = null,
    ): ?ResolvedPrice {
        $list = $from instanceof PriceList ? $from : $this->defaultList($from, $currency);

        if ($list === null || $list->isArchived()) {
            return null;
        }

        if ($item->isArchived() || ($item->company_id !== null && $item->company_id !== $list->company_id)) {
            return null;
        }

        $factor = $this->factor($item, $uomId);

        if ($factor === null) {
            return null;
        }

        $day = $this->day($list, $at);
        $quantity = BigDecimal::of((string) $quantity);
        $own = $this->find($list, $item, $uomId, $day, $quantity);

        if ($own !== null) {
            return $this->resolved($list, $own, $uomId, 'unit', '1', Money::ofMinor((string) $own->amount_minor, $list->currency));
        }

        if ($uomId === $item->base_uom_id) {
            return null;
        }

        $base = $this->find($list, $item, $item->base_uom_id, $day, $quantity->multipliedBy($factor));

        if ($base === null) {
            return null;
        }

        $money = Money::ofMinor((string) $base->amount_minor, $list->currency)->multiply((string) $factor);

        return $this->resolved($list, $base, $uomId, 'base', (string) $factor->strippedOfTrailingZeros(), $money);
    }

    /** The company's active default list in $currency (its base currency when null). */
    public function defaultList(Company $company, ?string $currency = null): ?PriceList
    {
        return PriceList::query()
            ->where('company_id', $company->id)
            ->where('currency', $currency ?? $company->base_currency)
            ->where('is_default', true)
            ->whereNull('archived_at')
            ->first();
    }

    /** How many base units one $uomId holds: 1 for the base unit, null when the item has no such unit. */
    private function factor(Item $item, string $uomId): ?BigDecimal
    {
        if ($uomId === $item->base_uom_id) {
            return BigDecimal::one();
        }

        $factor = $item->relationLoaded('uoms')
            ? $item->uoms->firstWhere('uom_id', $uomId)?->factor
            : ItemUom::query()->where('item_id', $item->id)->where('uom_id', $uomId)->value('factor');

        return $factor === null ? null : BigDecimal::of((string) $factor);
    }

    private function day(PriceList $list, CarbonInterface|string|null $at): string
    {
        if (is_string($at)) {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}\z/', $at) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $at, 'UTC') : false;

            if ($date === false || $date->toDateString() !== $at) {
                throw new InvalidArgumentException("Price date [{$at}] is not a calendar date (Y-m-d). Pass a date such as 2026-10-08, or a Carbon instance.");
            }

            return $at;
        }

        $zone = Company::query()->whereKey($list->company_id)->value('timezone') ?: 'UTC';

        return ($at ?? CarbonImmutable::now())->toImmutable()->setTimezone($zone)->toDateString();
    }

    private function find(PriceList $list, Item $item, string $uomId, string $day, BigDecimal $quantity): ?ItemPrice
    {
        return ItemPrice::query()
            ->where('price_list_id', $list->id)
            ->where('item_id', $item->id)
            ->where('uom_id', $uomId)
            ->whereNull('archived_at')
            ->where('effective_from', '<=', $day)
            ->where('min_quantity', '<=', (string) $quantity)
            ->orderByDesc('min_quantity')
            ->orderByDesc('effective_from')
            ->first();
    }

    private function resolved(PriceList $list, ItemPrice $price, string $uomId, string $source, string $factor, Money $money): ResolvedPrice
    {
        return new ResolvedPrice(
            money: $money,
            priceListId: $list->id,
            taxInclusive: (bool) $list->tax_inclusive,
            itemPriceId: $price->id,
            uomId: $uomId,
            source: $source,
            factor: $factor,
            effectiveFrom: $price->effective_from->toDateString(),
            minQuantity: $price->minQuantity(),
        );
    }

    /**
     * The prices in force on $day for items in $lists: per list, item and
     * unit, every quantity break's latest price (ItemResource's `prices`).
     *
     * @param  list<string>  $listIds
     */
    public static function currentQuery(array $listIds, string $day): Builder
    {
        return ItemPrice::query()
            ->whereIn('item_prices.price_list_id', $listIds)
            ->whereNull('item_prices.archived_at')
            ->where('item_prices.effective_from', '<=', $day)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('item_prices as later')
                ->whereColumn('later.price_list_id', 'item_prices.price_list_id')
                ->whereColumn('later.item_id', 'item_prices.item_id')
                ->whereColumn('later.uom_id', 'item_prices.uom_id')
                ->whereColumn('later.min_quantity', 'item_prices.min_quantity')
                ->whereNull('later.archived_at')
                ->where('later.effective_from', '<=', $day)
                ->whereColumn('later.effective_from', '>', 'item_prices.effective_from'));
    }
}
