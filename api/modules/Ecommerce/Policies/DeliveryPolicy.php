<?php

namespace Modules\Ecommerce\Policies;

use App\Core\Identity\Models\User;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Support\Access;

/**
 * RBAC-01, RBAC-04: who may do what with a delivery, at its location (a
 * branch or company role covers the locations under it).
 *
 * - view: the delivery board (`ecommerce.delivery.view`) or the order
 *   (`ecommerce.order.view`) there; the proof photo follows it.
 * - track (ECOM-40): the rider's position and trail,
 *   `ecommerce.delivery.track` there; personal data, apart from `view`.
 * - deliver (ECOM-28): the delivery's own rider, with
 *   `ecommerce.delivery.deliver` there.
 */
final class DeliveryPolicy
{
    public function __construct(private readonly Access $access) {}

    public function view(User $user, Delivery $delivery): bool
    {
        return $this->access->atLocation($user, 'ecommerce.delivery.view', $delivery->location_id)
            || $this->access->atLocation($user, 'ecommerce.order.view', $delivery->location_id);
    }

    public function track(User $user, Delivery $delivery): bool
    {
        return $this->access->atLocation($user, 'ecommerce.delivery.track', $delivery->location_id);
    }

    public function deliver(User $user, Delivery $delivery): bool
    {
        return $delivery->rider_id !== null
            && Rider::query()->whereKey($delivery->rider_id)->where('user_id', $user->id)->exists()
            && $this->access->atLocation($user, 'ecommerce.delivery.deliver', $delivery->location_id);
    }
}
