<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ecommerce\Delivery\ProofPhotos;
use Modules\Ecommerce\Delivery\RiderUpdates;
use Modules\Ecommerce\Http\Requests\ProofPhotoRequest;
use Modules\Ecommerce\Http\Requests\RiderUpdatesRequest;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Support\Access;

/**
 * ECOM-28: the rider screen of the mobile app, signed in as the rider
 * (decision 7): their own open deliveries (the customer's first name,
 * phone, address and what to collect; never another rider's), their
 * queued status and location updates (idempotent by id, NFR-04), and a
 * proof photo. Needs `ecommerce.delivery.deliver` at the delivery's
 * location.
 */
class RiderAppController
{
    public function __construct(private readonly Access $access, private readonly RiderUpdates $updates, private readonly ProofPhotos $photos) {}

    public function deliveries(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'ecommerce.delivery.deliver'), 403);
        $riders = Rider::query()->where('user_id', $request->user()->id)->whereNull('archived_at')->pluck('id');
        $deliveries = $this->access->byLocation(Delivery::query(), $request->user(), 'ecommerce.delivery.deliver')
            ->whereIn('rider_id', $riders)->whereIn('status', [Delivery::ASSIGNED, Delivery::PICKED_UP])
            ->orderBy('assigned_at')->limit(100)->get();
        $orders = Order::query()->whereKey($deliveries->pluck('order_id'))->get()->keyBy('id');

        return response()->json(['data' => $deliveries->map(function (Delivery $d) use ($orders) {
            $order = $orders[$d->order_id];

            return [
                'id' => $d->id,
                'status' => $d->status,
                'order_number' => $order->number,
                'customer_name' => trim(explode(' ', $order->contact_name)[0] ?? ''),
                'customer_phone' => $order->contact_phone,
                'address' => $order->address,
                'currency' => $d->currency,
                'collect_minor' => (string) $d->cod_due_minor,
                'proof_kind' => $d->proof_kind,
                'assigned_at' => $d->assigned_at?->toIso8601String(),
                'picked_up_at' => $d->picked_up_at?->toIso8601String(),
            ];
        })->values()]);
    }

    public function updates(RiderUpdatesRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'ecommerce.delivery.deliver'), 403);
        $results = $this->updates->upload($request->user(), $request->validated('updates'));
        $stored = collect($results)->contains(fn (array $r) => $r['status'] === 'stored');

        return response()->json(['results' => $results], $stored ? 200 : 422);
    }

    /** ECOM-41: the proof photo, through the media pipeline (ProofPhotos); the rider's own delivery only (ProofPhotoRequest). */
    public function photo(ProofPhotoRequest $request, Delivery $ecomDelivery): JsonResponse
    {
        $delivery = $this->photos->store($ecomDelivery, $request->file('photo'));

        return response()->json(['data' => ['id' => $delivery->id, 'proof_kind' => $delivery->proof_kind]], 201);
    }
}
