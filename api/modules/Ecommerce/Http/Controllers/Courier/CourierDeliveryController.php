<?php

namespace Modules\Ecommerce\Http\Controllers\Courier;

use App\Core\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Ecommerce\Courier\CourierDeliveries;
use Modules\Ecommerce\Http\Requests\Courier\BookDeliveryRequest;
use Modules\Ecommerce\Http\Requests\Courier\CourierStatusRequest;
use Modules\Ecommerce\Http\Resources\CourierOrderResource;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Support\Access;

/**
 * MKT-04, ECOM-27, ECOM-28 (ADR 024): a courier on the public API
 * (`ecommerce.delivery.write`) books a delivery with its tracking
 * (`ecommerce.delivery.assign` at the delivery's location) and reports
 * its progress (`ecommerce.delivery.deliver`), through CourierDeliveries.
 * Both are idempotent by the client's `reference`. A delivery the caller
 * cannot see (`ecommerce.order.view` there) is not found.
 */
class CourierDeliveryController
{
    public function __construct(private readonly Access $access, private readonly CourierDeliveries $couriers) {}

    public function book(BookDeliveryRequest $request, Delivery $ecomDelivery): JsonResponse
    {
        $this->authorise($request->user(), $ecomDelivery, 'ecommerce.delivery.assign');
        ['delivery' => $delivery, 'created' => $created] = $this->couriers->book($ecomDelivery, $request->user(), $request->validated());

        return CourierOrderResource::make($delivery->load('order'))->response()->setStatusCode($created ? 201 : 200);
    }

    public function status(CourierStatusRequest $request, Delivery $ecomDelivery): CourierOrderResource
    {
        $this->authorise($request->user(), $ecomDelivery, 'ecommerce.delivery.deliver');
        $this->couriers->status($ecomDelivery, $request->user(), $request->validated());

        return CourierOrderResource::make($ecomDelivery->refresh()->load('order'));
    }

    private function authorise(User $user, Delivery $delivery, string $permission): void
    {
        abort_unless($this->access->atLocation($user, 'ecommerce.order.view', $delivery->location_id)
            || $this->access->atLocation($user, $permission, $delivery->location_id), 404);
        $this->access->assertAtLocation($user, $permission, $delivery->location_id);
    }
}
