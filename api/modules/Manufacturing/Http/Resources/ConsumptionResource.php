<?php

namespace Modules\Manufacturing\Http\Resources;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\OrderMaterial;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-18: one material of an order, actual against standard: the standard
 * for the good output (required × good ÷ planned, 6 places half up) and its
 * expected wastage, the actual (issued − returned), the difference and its
 * share of the standard; for valued orders and holders of
 * `manufacturing.cost.view`, the actual value, the standard at the actual
 * unit cost (half up) and the difference.
 *
 * @mixin OrderMaterial
 */
class ConsumptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OrderMaterial $material */
        $material = $this->resource;
        /** @var Order $order */
        $order = $material->order;
        $planned = BigDecimal::of((string) $order->qty_base);
        $ratio = fn (string $qty) => $planned->isZero() ? Qty::zero() : Qty::of(Qty::ratio($qty, (string) $order->qty_good_base, $planned));
        $standard = $ratio((string) $material->qty_required_base);
        $actual = Qty::of(BigDecimal::of((string) $material->qty_issued_base)->minus((string) $material->qty_returned_base));
        $variance = Qty::of($actual->minus($standard));
        $costs = $order->isValued() && Costs::atLocation($request, $order->location_id);
        $value = BigInteger::of((string) $material->value_issued_minor)->minus((string) $material->value_returned_minor);
        $standardValue = $actual->isZero() ? BigInteger::zero() : Qty::share($value, $standard, $actual);

        return [
            'id' => $material->id,
            'order' => ['id' => $order->id, 'number' => $order->number, 'status' => $order->status, 'ledger' => $order->ledger],
            'company' => Refs::named($order->company),
            'location' => Refs::named($order->location),
            'item' => Refs::item($material->item),
            'uom' => Refs::uom($material->uom),
            'required' => Qty::show($material->qty_required_base),
            'standard' => Qty::show($standard),
            'expected_loss' => Qty::show($ratio((string) $material->expected_loss_base)),
            'actual' => Qty::show($actual),
            'variance_qty' => Qty::show($variance),
            'variance_percent' => $standard->isZero() ? null : Qty::show($variance->multipliedBy(100)->dividedBy($standard, 1, RoundingMode::HalfUp)),
            'valued' => $order->isValued(),
            'actual_value' => $costs ? Costs::money((string) $value, $order->currency) : null,
            'standard_value' => $costs && ! $actual->isZero() ? Costs::money((string) $standardValue, $order->currency) : null,
            'value_variance' => $costs && ! $actual->isZero() ? Costs::money((string) $value->minus($standardValue), $order->currency) : null,
        ];
    }
}
