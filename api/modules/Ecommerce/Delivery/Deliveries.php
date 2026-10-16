<?php

namespace Modules\Ecommerce\Delivery;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-27: assigning a delivery to a rider of its company, and assigning
 * again until it is picked up. Audited (the delivery model).
 */
final class Deliveries
{
    public function assign(Delivery $delivery, Rider $rider, User $by): Delivery
    {
        return Tx::run(function () use ($delivery, $rider) {
            $delivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if (! in_array($delivery->status, [Delivery::UNASSIGNED, Delivery::ASSIGNED], true)) {
                throw EcomError::make('delivery_not_assignable', status: 409);
            }

            if ($rider->company_id !== $delivery->company_id || ! $rider->active || $rider->archived_at !== null || $rider->user?->status !== User::STATUS_ACTIVE) {
                throw EcomError::make('rider_unavailable', field: 'rider_id');
            }

            $order = Order::query()->findOrFail($delivery->order_id);

            if (! in_array($order->status, [Order::ACCEPTED, Order::PICKING, Order::READY], true)) {
                throw EcomError::make('delivery_not_assignable', status: 409);
            }

            $delivery->forceFill(['rider_id' => $rider->id, 'status' => Delivery::ASSIGNED, 'assigned_at' => CarbonImmutable::now()])->save();

            return $delivery;
        });
    }
}
