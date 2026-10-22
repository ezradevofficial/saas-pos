<?php

namespace App\Core\Marketplace\Connectors\Courier;

/** MKT-04: the driver until the owner chooses a courier: every call answers 503 `courier_not_configured`. */
final class UnconfiguredCourier implements CourierDriver
{
    public function key(): string
    {
        return 'none';
    }

    public function configured(): bool
    {
        return false;
    }

    public function quote(ShipmentRequest $request): ShipmentQuote
    {
        throw CourierUnavailable::notConfigured();
    }

    public function create(ShipmentRequest $request): Shipment
    {
        throw CourierUnavailable::notConfigured();
    }

    public function track(string $providerReference): Shipment
    {
        throw CourierUnavailable::notConfigured();
    }

    public function cancel(string $providerReference): Shipment
    {
        throw CourierUnavailable::notConfigured();
    }
}
