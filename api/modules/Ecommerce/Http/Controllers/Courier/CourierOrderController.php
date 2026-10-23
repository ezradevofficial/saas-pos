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
 * Anything else is not found, as out of reach is (review L5). A courier
 * stopped at a company (its rider archived or switched off) gets 403
 * `courier_stopped` there, and nothing of that company in the list; one
 * stopped everywhere it has worked gets 403 for the list (review H1).
 */
class CourierOrderController
{
    public function __construct(private readonly Access $access) {}

    public function index(CourierOrdersRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'ecommerce.order.view'), 403);
        $riders = CourierDeliveries::ridersOf($user);

        if ($riders['any'] && $riders['active'] === []) {
            throw CourierDeliveries::error('courier_stopped', 403);
        }

        $status = $request->validated('status', 'open');
        $waiting = fn ($q) => $q->where('status', Delivery::UNASSIGNED)
            ->whereIn('order_id', Order::query()->whereIn('status', CourierDeliveries::BOOKABLE_ORDER_STATUSES)->select('id'));
        $mine = fn ($q, bool $open) => $q->whereIn('rider_id', $riders['active'])->when($open, fn ($w) => $w->whereIn('status', CourierDeliveries::HELD));

        $query = $this->access->byLocation(Delivery::query(), $user, 'ecommerce.order.view')
            ->whereNotIn('company_id', $riders['stopped_companies'])
            ->where(fn ($q) => match ($status) {
                'ready' => $waiting($q),
                'handed' => $mine($q, false),
                'all' => $q->where(fn ($w) => $waiting($w))->orWhere(fn ($w) => $mine($w, false)),
                default => $q->where(fn ($w) => $waiting($w))->orWhere(fn ($w) => $mine($w, true)),
            })
            ->with(CourierOrderResource::WITH)->orderBy('created_at')->orderBy('id');

        return CourierOrderResource::collection($query->paginate((int) $request->validated('per_page', 50))->withQueryString());
    }

    public function show(CourierShowRequest $request, Order $ecomOrder): CourierOrderResource
    {
        $user = $request->user();
        abort_unless($ecomOrder->fulfilment === 'delivery' && $this->access->atLocation($user, 'ecommerce.order.view', $ecomOrder->location_id), 404);
        CourierDeliveries::assertNotStopped($user, $ecomOrder->company_id);
        $delivery = Delivery::query()->where('order_id', $ecomOrder->id)->with(CourierOrderResource::WITH)->first();
        abort_unless($delivery !== null && (CourierDeliveries::waiting($delivery) || CourierDeliveries::handedTo($delivery, $user)), 404);

        return CourierOrderResource::make($delivery);
    }
}
