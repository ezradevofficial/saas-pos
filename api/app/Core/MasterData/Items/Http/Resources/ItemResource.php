<?php

namespace App\Core\MasterData\Items\Http\Resources;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemBarcode;
use App\Core\MasterData\Items\ItemImage;
use App\Core\MasterData\Items\ItemImages;
use App\Core\MasterData\Items\ItemUom;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item (MD-02). `uoms` are the other units with their factor to the
 * base unit (a decimal string); `barcodes` are normalised, `uom_id` null
 * for the base unit; `images` carry a temporary URL for the requesting
 * user, valid ItemImages::URL_MINUTES. Fields hidden from the user by
 * field rules on `item` (RBAC-05) are left out, as in its history; they
 * are named as the model's columns, plus `uoms`, `barcodes`, `images` and
 * `prices` (the item's prices per price list, on the detail endpoint).
 *
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    public const FIELD_RULES = 'item';

    /**
     * MD-03 follow-up: the item's prices per price list (CurrentPrices),
     * set on the detail endpoint only; hidden with the `prices` field.
     *
     * @var list<array<string, mixed>>|null
     */
    public ?array $prices = null;

    /** @param list<array<string, mixed>>|null $prices */
    public function withPrices(?array $prices): static
    {
        $this->prices = $prices;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $images = app(ItemImages::class);

        return HidesFields::apply($request, self::FIELD_RULES, [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'shared' => $this->isShared(),
            'code' => (string) $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'category_id' => $this->category_id,
            'base_uom_id' => $this->base_uom_id,
            'tax_category_id' => $this->tax_category_id,
            'uoms' => $this->uoms->map(fn (ItemUom $uom) => [
                'uom_id' => $uom->uom_id,
                'code' => strtoupper((string) $uom->uom?->code),
                'factor' => (string) BigDecimal::of($uom->factor)->strippedOfTrailingZeros(),
                'is_sales_default' => $uom->is_sales_default,
                'is_purchase_default' => $uom->is_purchase_default,
            ])->values()->all(),
            'barcodes' => $this->barcodes->map(fn (ItemBarcode $barcode) => [
                'barcode' => $barcode->barcode,
                'uom_id' => $barcode->uom_id,
            ])->values()->all(),
            // Left out when not loaded: exports skip images and their signed URLs.
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn (ItemImage $image) => [
                'id' => $image->id,
                'position' => $image->position,
                'url' => $request->user() === null ? null : $images->url($image, $request->user()),
                'mime' => $image->mime,
                'width' => $image->width,
                'height' => $image->height,
                'size' => $image->size,
            ])->values()->all()),
            'prices' => $this->when($this->prices !== null, fn () => $this->prices),
            'custom' => (object) ($this->custom ?? []),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ]);
    }
}
