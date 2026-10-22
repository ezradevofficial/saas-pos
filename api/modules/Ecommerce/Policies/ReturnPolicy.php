<?php

namespace Modules\Ecommerce\Policies;

use App\Core\Identity\Models\User;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderReturn;
use Modules\Ecommerce\Support\Access;

/**
 * RBAC-01, RBAC-04, RBAC-06: returns and exchanges of an order, at its
 * location. Reading follows the order (`ecommerce.order.view`); taking
 * goods back and collecting an exchange's difference is
 * `ecommerce.order.manage` (as marking an order returned); paying money
 * back is `ecommerce.order.refund`, within the user's `max_refund_amount`
 * (OrderFlow::assertRefundLimit).
 */
final class ReturnPolicy
{
    public function __construct(private readonly Access $access) {}

    public function view(User $user, Order $order): bool
    {
        return $this->access->atLocation($user, 'ecommerce.order.view', $order->location_id);
    }

    public function create(User $user, Order $order): bool
    {
        return $this->access->atLocation($user, 'ecommerce.order.manage', $order->location_id);
    }

    public function refund(User $user, OrderReturn $return): bool
    {
        return $this->access->atLocation($user, 'ecommerce.order.refund', $return->location_id);
    }

    public function collect(User $user, OrderReturn $return): bool
    {
        return $this->access->atLocation($user, 'ecommerce.order.manage', $return->location_id);
    }

    public function viewReturn(User $user, OrderReturn $return): bool
    {
        return $this->access->atLocation($user, 'ecommerce.order.view', $return->location_id);
    }
}
