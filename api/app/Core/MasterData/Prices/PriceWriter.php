<?php

namespace App\Core\MasterData\Prices;

use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets item prices in a price list (MD-03 follow-up), one or many, all or
 * nothing. Under a lock on the price list row (writes to one list run one
 * at a time), every row is checked before any is written:
 *
 * - the list is active (422 `price_list_archived`);
 * - `currency` is the list's (`currency_mismatch`): amounts are minor units
 *   of that currency (ADR 003);
 * - the item is active and shared or the list's company's (TEN-08:
 *   `item_other_company`), and the unit is its base unit or one of its
 *   other units (`uom_not_on_item`);
 * - the same list, item, unit, start date and quantity break once per call.
 *
 * Then each row is an upsert: the active price with the same list, item,
 * unit, start date (default: today in the company's time zone) and
 * quantity break (default 1) gets the new amount, or a new price is
 * created. Each change is audited (ItemPrice).
 */
class PriceWriter
{
    /**
     * @param  list<array{item_id: string, uom_id: string, amount_minor: string, currency: string, effective_from?: ?string, min_quantity?: int|string|null}>  $rows
     * @param  string|null  $prefix  error key prefix per row (`prices`: `prices.3.uom_id`); null for one row
     * @return list<array{price: ItemPrice, created: bool}>
     */
    public function write(PriceList $priceList, array $rows, ?string $prefix = null): array
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($priceList, $rows, $prefix) {
            $list = PriceList::query()->whereKey($priceList->id)->lockForUpdate()->firstOrFail();

            if ($list->isArchived()) {
                throw new ApiException(422, 'price_list_archived', __('core.price.price_list_archived'));
            }

            $today = self::today($list);
            $rows = array_map(fn (array $row) => [
                ...$row,
                'effective_from' => ($row['effective_from'] ?? null) ?: $today,
                'min_quantity' => (string) BigDecimal::of((string) ($row['min_quantity'] ?? '1'))->toScale(6),
            ], $rows);

            $this->check($list, $rows, $prefix);

            return array_map(fn (array $row) => $this->upsert($list, $row), $rows);
        });
    }

    /** Today in the price list's company's time zone (UTC when unknown), Y-m-d. */
    public static function today(PriceList $list): string
    {
        $zone = Company::query()->whereKey($list->company_id)->value('timezone') ?: 'UTC';

        return CarbonImmutable::now($zone)->toDateString();
    }

    /** @param list<array<string, mixed>> $rows */
    private function check(PriceList $list, array $rows, ?string $prefix): void
    {
        $items = Item::query()->whereIn('id', array_unique(array_column($rows, 'item_id')))->get()->keyBy('id');
        $units = ItemUom::query()->whereIn('item_id', $items->keys())->get(['item_id', 'uom_id'])
            ->groupBy('item_id')->map(fn ($uoms) => $uoms->pluck('uom_id')->all());
        $key = fn (int $index, string $field) => $prefix === null ? $field : "{$prefix}.{$index}.{$field}";
        $errors = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if ($row['currency'] !== $list->currency) {
                $errors[$key($index, 'currency')][] = __('core.price.currency_mismatch', ['currency' => $list->currency]);
            }

            $item = $items->get($row['item_id']);

            if ($item === null) {
                $errors[$key($index, 'item_id')][] = __('core.price.item_missing');

                continue;
            }

            if ($item->isArchived()) {
                $errors[$key($index, 'item_id')][] = __('core.price.item_archived');
            } elseif ($item->company_id !== null && $item->company_id !== $list->company_id) {
                $errors[$key($index, 'item_id')][] = __('core.price.item_other_company');
            }

            if ($row['uom_id'] !== $item->base_uom_id && ! in_array($row['uom_id'], $units->get($item->id, []), true)) {
                $errors[$key($index, 'uom_id')][] = __('core.price.uom_not_on_item');
            }

            $identity = implode('|', [$row['item_id'], $row['uom_id'], $row['effective_from'], $row['min_quantity']]);

            if (isset($seen[$identity])) {
                $errors[$key($index, 'item_id')][] = __('core.price.duplicate_row', ['row' => $seen[$identity] + 1]);
            }

            $seen[$identity] ??= $index;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{price: ItemPrice, created: bool}
     */
    private function upsert(PriceList $list, array $row): array
    {
        $price = ItemPrice::query()
            ->where('price_list_id', $list->id)
            ->where('item_id', $row['item_id'])
            ->where('uom_id', $row['uom_id'])
            ->where('effective_from', $row['effective_from'])
            ->where('min_quantity', $row['min_quantity'])
            ->whereNull('archived_at')
            ->first();

        if ($price !== null) {
            $price->amount_minor = (string) $row['amount_minor'];
            $price->save();

            return ['price' => $price, 'created' => false];
        }

        $price = ItemPrice::create([
            'price_list_id' => $list->id,
            'item_id' => $row['item_id'],
            'uom_id' => $row['uom_id'],
            'amount_minor' => (string) $row['amount_minor'],
            'currency' => $list->currency,
            'effective_from' => $row['effective_from'],
            'min_quantity' => $row['min_quantity'],
        ]);

        return ['price' => $price, 'created' => true];
    }

    /**
     * Restore an archived price: refused while another active price has
     * its list, item, unit, start date and quantity break (422
     * `price_exists`), or while its list is archived.
     */
    public function restore(ItemPrice $price): ItemPrice
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($price) {
            $list = PriceList::query()->whereKey($price->price_list_id)->lockForUpdate()->firstOrFail();
            $price = ItemPrice::query()->whereKey($price->id)->firstOrFail();

            if (! $price->isArchived()) {
                return $price;
            }

            if ($list->isArchived()) {
                throw new ApiException(422, 'price_list_archived', __('core.price.price_list_archived'));
            }

            $taken = ItemPrice::query()
                ->where('price_list_id', $price->price_list_id)
                ->where('item_id', $price->item_id)
                ->where('uom_id', $price->uom_id)
                ->where('effective_from', $price->effective_from->toDateString())
                ->where('min_quantity', $price->min_quantity)
                ->whereNull('archived_at')
                ->exists();

            if ($taken) {
                throw new ApiException(422, 'price_exists', __('core.price.price_exists'));
            }

            $price->restore();

            return $price;
        });
    }
}
