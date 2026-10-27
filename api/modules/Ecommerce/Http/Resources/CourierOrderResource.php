<?php

namespace Modules\Ecommerce\Http\Resources;

use App\Core\MasterData\Items\Http\Resources\HidesFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ecommerce\Courier\CourierDeliveries;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderLine;

/**
 * MKT-04, ECOM-27 (ADR 024): an online order to deliver, as a courier sees
 * it through the public API. The customer's name, phone, address and
 * delivery notes are given only once the delivery is handed to the
 * courier reading it; before that it sees the area (zone), the parcel and
 * what to collect, enough to quote and book. Fields its role's field rules
 * hide (RBAC-05, resource `ecom_order`) are left out. A rider's position
 * or trail is never part of it.
 *
 * @mixin Delivery
 */
class CourierOrderResource extends JsonResource
{
    /** The relations every answer reads (review L9: no query per row). */
    public const WITH = ['order.lines', 'order.location', 'order.zone'];

    /** Fields shown only to the courier the delivery is handed to. */
    public const HANDED_ONLY = ['contact_name', 'contact_phone', 'address', 'notes'];

    public const FIELD_RULES_RESOURCE = 'ecom_order';

    public function toArray(Request $request): array
    {
        /** @var Delivery $delivery */
        $delivery = $this->resource;
        /** @var Order $order */
        $order = $delivery->order;
        // Review L9: the order, its location, zone and lines come eager-loaded; the caller's riders once per request.
        $handed = $request->user() !== null && CourierDeliveries::handedTo($delivery, $request->user());
        $at = fn ($value) => $value === null ? null : ($value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value))->format(DATE_ATOM);

        $data = [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'company_id' => $order->company_id,
            'location' => ['id' => $order->location_id, 'name' => (string) $order->location?->name],
            'zone' => $order->zone_id === null ? null : ['id' => $order->zone_id, 'name' => (string) $order->zone?->name],
            'currency' => $order->currency,
            'total_minor' => (string) $order->total_minor,
            'collect_minor' => (string) $delivery->cod_due_minor,
            'payment_status' => $order->payment_status,
            'placed_at' => $at($order->placed_at),
            'ready_at' => $at($order->ready_at),
            'lines' => $order->lines->map(fn (OrderLine $line) => ['name' => $line->name, 'qty' => (string) $line->qty])->values()->all(),
            'handed_to_you' => $handed,
            'contact_name' => $handed ? $order->contact_name : null,
            'contact_phone' => $handed ? $order->contact_phone : null,
            'address' => $handed ? $order->address : null,
            'notes' => $handed ? $order->notes : null,
            'delivery' => [
                'id' => $delivery->id,
                'status' => $delivery->status,
                'tracking_reference' => $handed ? $delivery->tracking_reference : null,
                'tracking_url' => $handed ? $delivery->tracking_url : null,
                'booked_at' => $handed ? $at($delivery->booked_at) : null,
                'assigned_at' => $handed ? $at($delivery->assigned_at) : null,
                'picked_up_at' => $handed ? $at($delivery->picked_up_at) : null,
                'delivered_at' => $handed ? $at($delivery->delivered_at) : null,
                'failed_at' => $handed ? $at($delivery->failed_at) : null,
                'reason' => $handed ? $delivery->reason : null,
            ],
        ];

        return HidesFields::apply($request, self::FIELD_RULES_RESOURCE, $data);
    }
}
