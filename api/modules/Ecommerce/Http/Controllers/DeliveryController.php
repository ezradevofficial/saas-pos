<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Ecommerce\Delivery\Deliveries;
use Modules\Ecommerce\Delivery\RiderLocations;
use Modules\Ecommerce\Http\Requests\AssignRiderRequest;
use Modules\Ecommerce\Http\Requests\ListRequest;
use Modules\Ecommerce\Http\Requests\TrackDeliveryRequest;
use Modules\Ecommerce\Http\Resources\Present;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Support\Access;

/**
 * ECOM-27, ECOM-29: the delivery board (by status) at the locations where
 * the user holds `ecommerce.delivery.view`, and rider assignment
 * (`.assign`). ECOM-40: a delivery's last reported position (on the board)
 * and its trail (`track`) only with `ecommerce.delivery.track` there.
 */
class DeliveryController
{
    public function __construct(private readonly Access $access, private readonly Deliveries $deliveries) {}

    public function index(ListRequest $request): JsonResponse
    {
        $status = $request->validated('status', 'open');
        $query = $this->access->byLocation(Delivery::query(), $request->user(), 'ecommerce.delivery.view')
            ->when($status === 'open', fn ($q) => $q->whereIn('status', [Delivery::UNASSIGNED, Delivery::ASSIGNED, Delivery::PICKED_UP]))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($request->filled('rider'), fn ($q) => $q->where('rider_id', $request->validated('rider')))
            ->orderByRaw("case status when 'unassigned' then 0 when 'assigned' then 1 when 'picked_up' then 2 else 3 end")->orderByDesc('created_at');
        $page = $query->paginate($request->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Delivery $d) => Present::delivery($d, true, $request->user()))->values(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function assign(AssignRiderRequest $request, Delivery $ecomDelivery): JsonResponse
    {
        abort_unless($this->access->atLocation($request->user(), 'ecommerce.delivery.view', $ecomDelivery->location_id), 404);
        $this->access->assertAtLocation($request->user(), 'ecommerce.delivery.assign', $ecomDelivery->location_id);
        $rider = Rider::query()->findOrFail($request->validated('rider_id'));

        return response()->json(['data' => Present::delivery($this->deliveries->assign($ecomDelivery, $rider, $request->user()), true, $request->user())]);
    }

    /** ECOM-40: where the rider is, and the trail of the active delivery (cleared once it ends). */
    public function track(TrackDeliveryRequest $request, Delivery $ecomDelivery, RiderLocations $locations): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $ecomDelivery->id,
            'status' => $ecomDelivery->status,
            'active' => in_array($ecomDelivery->status, Delivery::ACTIVE, true),
            'last_location' => Present::location($ecomDelivery),
            'trail' => $locations->trail($ecomDelivery),
        ]]);
    }
}
