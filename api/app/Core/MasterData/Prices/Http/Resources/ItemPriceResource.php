<?php

namespace App\Core\MasterData\Prices\Http\Resources;

use App\Core\MasterData\Items\Http\Resources\HidesFields;
use App\Core\MasterData\Prices\Http\ItemVisibility;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Prices\PriceAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item price (MD-03 follow-up). `amount_minor` is a string of minor
 * units in `currency`, the list's (ADR 003); `min_quantity` a decimal
 * string. `state` (when the list query computed it): `current` (in force
 * today in the company's time zone), `scheduled` (starts later) or
 * `replaced` (a later price of the same unit and break is in force).
 *
 * `item_code` and `item_name` are left out for a user who can't view the
 * item (`core.item.view`; ItemVisibility). RBAC-05, on the `item` field
 * rules resource: `item_code` and `item_name`
 * go with the item's `code` and `name`; `amount_minor` with `prices`.
 *
 * @mixin ItemPrice
 */
class ItemPriceResource extends JsonResource
{
    /** Output key => item fields it is built from (RBAC-05). */
    public const SOURCES = [
        'item_code' => ['code'],
        'item_name' => ['name'],
        'amount_minor' => [PriceAccess::FIELD],
    ];

    public function toArray(Request $request): array
    {
        // RBAC-04: the item's code and name only for users who may view the item.
        $visibility = ItemVisibility::for($request, $this->price_list_id);
        $seesItem = fn () => $this->item->company_id === null ? $visibility['shared'] : $visibility['company'];

        return HidesFields::apply($request, PriceAccess::FIELD_RULES, [
            'id' => $this->id,
            'price_list_id' => $this->price_list_id,
            'item_id' => $this->item_id,
            'item_code' => $this->when($this->relationLoaded('item') && $seesItem(), fn () => (string) $this->item->code),
            'item_name' => $this->when($this->relationLoaded('item') && $seesItem(), fn () => $this->item->name),
            'uom_id' => $this->uom_id,
            'uom_code' => $this->whenLoaded('uom', fn () => strtoupper((string) $this->uom->code)),
            'amount_minor' => (string) $this->amount_minor,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from?->toDateString(),
            'min_quantity' => $this->minQuantity(),
            'state' => $this->when(isset($this->resource->getAttributes()['state']), fn () => $this->resource->getAttributes()['state']),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ], self::SOURCES);
    }
}
