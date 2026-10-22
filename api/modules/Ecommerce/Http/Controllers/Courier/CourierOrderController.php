<?php

namespace Modules\Ecommerce\Http\Controllers\Courier;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Ecommerce\Courier\CourierDeliveries;
use Modules\Ecommerce\Http\Requests\Courier\CourierOrdersRequest;
use Modules\Ecommerce\Http\Requests\Courier\CourierShowRequest;
use Modules\Ecommerce\Http\Resources\CourierOrderResource;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Support\Access;

/**
 * MKT-04, ECOM-27 (ADR 024): online orders to deliver, for a courier on
 * the public API (`ecommerce.order.read`): those waiting for a courier
 * (accepted, being picked or ready, with no rider) and those handed to
 * the caller, at the locations where it holds `ecommerce.order.view`.
 * Out of reach is not found.
 */
class CourierOrderController
{
    public function __construct(private readonly Access $access) {}

    public function index(CourierOrdersRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'ecommerce.order.view'), 403);
        $status = $request->validated('status', 'open');
        $riders = CourierDeliveries::riderIds($user);
        $waiting = fn ($q) => $q->where('status', Delivery::UNASSIGNED)
            ->whereIn('order_id', Order::query()->whereIn('status', CourierDeliveries::BOOKABLE_ORDER_STATUSES)->select('id'));
        $mine = fn ($q, bool $open) => $q->whereIn('rider_id', $riders)->when($open, fn ($w) => $w->whereIn('status', CourierDeliveries::HELD));

        $query = $this->access->byLocation(Delivery::query(), $user, 'ecommerce.order.view')
            ->where(fn ($q) => match ($status) {
                'ready' => $waiting($q),
                'handed' => $mine($q, false),
                'all' => $q->where(fn ($w) => $waiting($w))->orWhere(fn ($w) => $mine($w, false)),
                default => $q->where(fn ($w) => $waiting($w))->orWhere(fn ($w) => $mine($w, true)),
            })
            ->with('order')->orderBy('created_at')->orderBy('id');

        return CourierOrderResource::collection($query->paginate((int) $request->validated('per_page', 50))->withQueryString());
    }

    public function show(CourierShowRequest $request, Order $ecomOrder): CourierOrderResource
    {
        abort_unless($ecomOrder->fulfilment === 'delivery' && $this->access->atLocation($request->user(), 'ecommerce.order.view', $ecomOrder->location_id), 404);
        $delivery = Delivery::query()->where('order_id', $ecomOrder->id)->with('order')->first();
        abort_if($delivery === null, 404);

        return CourierOrderResource::make($delivery);
    }
}
