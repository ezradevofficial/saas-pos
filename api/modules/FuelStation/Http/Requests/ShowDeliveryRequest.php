<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/deliveries/{id} (FUEL-25): `fuel.delivery.view` at the station. */
class ShowDeliveryRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.delivery.view';
    }

    protected function routeRecord(): ?string
    {
        return 'fuel_delivery';
    }

    public function rules(): array
    {
        return [];
    }
}
