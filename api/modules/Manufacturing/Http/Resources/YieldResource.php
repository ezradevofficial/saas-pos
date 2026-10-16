<?php

namespace Modules\Manufacturing\Http\Resources;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\OrderOutput;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-19: an order's yield: planned, good and scrap (the order's unit),
 * yield (good ÷ planned) and scrap share (scrap ÷ (good + scrap)), in
 * percent with 1 place, its by-products reported, and for valued orders
 * and holders of `manufacturing.cost.view` its material, labour, overhead,
 * relieved and variance amounts.
 *
 * @mixin Order
 */
class YieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $planned = BigDecimal::of((string) $order->qty_base);
        $good = BigDecimal::of((string) $order->qty_good_base);
        $scrap = BigDecimal::of((string) $order->qty_scrap_base);
        $made = $good->plus($scrap);
        $costs = $order->isValued() && Costs::atLocation($request, $order->location_id);
        $money = fn ($minor) => $costs && $minor !== null ? Costs::money((string) $minor, $order->currency) : null;

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'ledger' => $order->ledger,
            'company' => Refs::named($order->company),
            'location' => Refs::named($order->location),
            'item' => Refs::item($order->item),
            'uom' => Refs::uom($order->uom),
            'released_at' => Refs::at($order->released_at),
            'planned' => Qty::show($order->qty),
            'good' => Qty::show(OrderResource::inUnit($order, (string) $order->qty_good_base)),
            'scrap' => Qty::show(OrderResource::inUnit($order, (string) $order->qty_scrap_base)),
            'yield_percent' => $planned->isZero() ? null : Qty::show($good->multipliedBy(100)->dividedBy($planned, 1, RoundingMode::HalfUp)),
            'scrap_percent' => $made->isZero() ? null : Qty::show($scrap->multipliedBy(100)->dividedBy($made, 1, RoundingMode::HalfUp)),
            'by_products' => $order->outputs->where('kind', OrderOutput::BY_PRODUCT)->values()->map(fn (OrderOutput $output) => [
                'item' => Refs::item($output->item), 'qty_planned' => Qty::show($output->qty_planned_base), 'qty_reported' => Qty::show($output->qty_reported_base),
            ])->all(),
            'valued' => $order->isValued(),
            'material_cost' => $money($order->material_cost_minor),
            'labour_cost' => $money($order->labour_cost_minor),
            'overhead_cost' => $money($order->overhead_cost_minor),
            'relieved' => $money($order->relieved_minor),
            'variance' => $money($order->variance_minor),
        ];
    }
}
