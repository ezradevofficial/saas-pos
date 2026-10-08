<?php

namespace App\Core\MasterData\Prices;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * An item's prices for its detail page (MD-03 follow-up): every active
 * price list the user reads prices of (`core.price.view|edit`; a company's
 * item: that company's lists only, TEN-08), each with the prices in force
 * today in the list's company's time zone (one per unit and quantity
 * break) and the scheduled ones (starting later). Lists without prices are
 * included, so a permitted user can add one. Null when the user reads no
 * prices, or field rules hide them (RBAC-05).
 */
class CurrentPrices
{
    public function __construct(private readonly PriceAccess $access) {}

    /** @return list<array<string, mixed>>|null */
    public function forItem(Item $item, User $user): ?array
    {
        if ($this->access->hidden($user)) {
            return null;
        }

        $companies = $this->access->companies($user);

        if ($companies === []) {
            return null;
        }

        $lists = PriceList::query()
            ->whereNull('archived_at')
            ->when($companies !== null, fn ($q) => $q->whereIn('company_id', $companies))
            ->when($item->company_id !== null, fn ($q) => $q->where('company_id', $item->company_id))
            ->orderByDesc('is_default')->orderBy('name')->orderBy('id')
            ->get();

        $units = [$item->base_uom_id, ...ItemUom::query()->where('item_id', $item->id)->pluck('uom_id')->all()];
        $zones = Company::query()->whereIn('id', $lists->pluck('company_id')->unique())->pluck('timezone', 'id');
        $frozen = $this->access->frozen($user);

        return $lists->map(function (PriceList $list) use ($item, $units, $zones, $user, $frozen) {
            $day = CarbonImmutable::now($zones[$list->company_id] ?: 'UTC')->toDateString();
            $current = PriceResolver::currentQuery([$list->id], $day)
                ->where('item_prices.item_id', $item->id)->whereIn('item_prices.uom_id', $units)
                ->with('uom:id,code')->orderBy('item_prices.uom_id')->orderBy('item_prices.min_quantity')->get();
            $scheduled = ItemPrice::query()
                ->where('price_list_id', $list->id)->where('item_id', $item->id)->whereIn('uom_id', $units)
                ->whereNull('archived_at')->where('effective_from', '>', $day)
                ->with('uom:id,code')->orderBy('effective_from')->orderBy('uom_id')->get();

            return [
                'price_list_id' => $list->id,
                'name' => $list->name,
                'company_id' => $list->company_id,
                'currency' => $list->currency,
                'tax_inclusive' => $list->tax_inclusive,
                'is_default' => $list->is_default,
                'can_edit' => ! $frozen && $this->access->canEdit($user, $list->company_id),
                'prices' => $this->rows($current),
                'scheduled' => $this->rows($scheduled),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, ItemPrice>  $prices
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $prices): array
    {
        return $prices->map(fn (ItemPrice $price) => [
            'id' => $price->id,
            'uom_id' => $price->uom_id,
            'uom_code' => strtoupper((string) $price->uom?->code),
            'amount_minor' => (string) $price->amount_minor,
            'currency' => $price->currency,
            'effective_from' => $price->effective_from->toDateString(),
            'min_quantity' => $price->minQuantity(),
        ])->values()->all();
    }
}
