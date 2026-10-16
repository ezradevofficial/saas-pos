<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Ecommerce\Delivery\Deliveries;
use Modules\Ecommerce\Http\Requests\AssignRiderRequest;
use Modules\Ecommerce\Http\Requests\ListRequest;
use Modules\Ecommerce\Http\Resources\Present;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Support\Access;

/**
 * ECOM-27, ECOM-29: the delivery board (by status, with each delivery's
 * last reported position) at the locations where the user holds
 * `ecommerce.delivery.view`, and rider assignment (`.assign`).
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

        return response()->json(['data' => $page->getCollection()->map(fn (Delivery $d) => Present::delivery($d))->values(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function assign(AssignRiderRequest $request, Delivery $ecomDelivery): JsonResponse
    {
        abort_unless($this->access->atLocation($request->user(), 'ecommerce.delivery.view', $ecomDelivery->location_id), 404);
        $this->access->assertAtLocation($request->user(), 'ecommerce.delivery.assign', $ecomDelivery->location_id);
        $rider = Rider::query()->findOrFail($request->validated('rider_id'));

        return response()->json(['data' => Present::delivery($this->deliveries->assign($ecomDelivery, $rider, $request->user()))]);
    }
}
