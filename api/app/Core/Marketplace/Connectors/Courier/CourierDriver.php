<?php

namespace App\Core\Marketplace\Connectors\Courier;

/**
 * MKT-04: the generic courier interface the courier connector books
 * deliveries through. One adapter per courier, chosen by
 * `marketplace.connectors.courier.driver`; until the owner picks a courier
 * and gives its credentials the driver is `none` (not configured). Amounts
 * are minor units with their ISO 4217 currency.
 */
interface CourierDriver
{
    /** A stable key (`none`, `fake`, a courier's name). */
    public function key(): string;

    /** Whether credentials are present, so bookings can be made. */
    public function configured(): bool;

    /** What a delivery would cost, before booking. */
    public function quote(ShipmentRequest $request): ShipmentQuote;

    /** Book a pickup; idempotent on the request's reference. */
    public function create(ShipmentRequest $request): Shipment;

    /** The shipment's current state, by the courier's reference. */
    public function track(string $providerReference): Shipment;

    public function cancel(string $providerReference): Shipment;
}
