<?php

namespace Modules\Manufacturing\Http\Resources;

use Brick\Math\BigInteger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\Backflush;
use Modules\Manufacturing\Models\BackflushLine;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-07, MFG-08: what one sale consumed through its menu items' recipes,
 * or what its void or a refund gave back: the menu items, each ingredient
 * (base unit) and, once valued and for holders of
 * `manufacturing.cost.view` at the location, the values; sale lines not
 * backflushed and why.
 *
 * @mixin Backflush
 */
class BackflushResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Backflush $backflush */
        $backflush = $this->resource;
        $costs = $backflush->valued_at !== null && Costs::atLocation($request, $backflush->location_id);
        $total = BigInteger::zero();

        foreach ($backflush->lines as $line) {
            $total = $total->plus((string) ($line->value_minor ?? 0));
        }

        return [
            'id' => $backflush->id,
            'kind' => $backflush->kind,
            'source_type' => $backflush->source_type,
            'source_id' => $backflush->source_id,
            'sale_id' => $backflush->sale_id,
            'reference' => $backflush->reference,
            'occurred_at' => Refs::at($backflush->occurred_at),
            'location' => Refs::named($backflush->location),
            'valued' => $backflush->valued_at !== null,
            'value' => $costs ? Costs::money((string) $total, $backflush->currency) : null,
            'menu_items' => $backflush->lines->map(fn (BackflushLine $line) => Refs::item($line->soldItem))->unique('id')->values()->all(),
            'lines' => $backflush->lines->map(fn (BackflushLine $line) => [
                'id' => $line->id,
                'sale_line_id' => $line->sale_line_id,
                'sold_item' => Refs::item($line->soldItem),
                'item' => Refs::item($line->item),
                'qty_base' => Qty::show($line->qty_base),
                'qty_returned_base' => Qty::show($line->qty_returned_base),
                'value' => $costs ? Costs::money($line->value_minor, $backflush->currency) : null,
            ])->all(),
            'skipped' => $backflush->skipped ?? [],
            'flags' => $backflush->flags ?? [],
        ];
    }
}
