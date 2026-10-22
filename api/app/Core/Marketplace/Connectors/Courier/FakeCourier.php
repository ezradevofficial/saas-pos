<?php

namespace App\Core\Marketplace\Connectors\Courier;

/**
 * MKT-04: a courier that books nothing, for local development and tests
 * only (Couriers refuses it elsewhere). A flat fee per parcel; bookings are
 * kept in memory and idempotent on the reference.
 */
final class FakeCourier implements CourierDriver
{
    public const FEE_PER_PARCEL_MINOR = 25_000;

    /** @var array<string, Shipment> provider reference => shipment */
    private array $shipments = [];

    public function key(): string
    {
        return 'fake';
    }

    public function configured(): bool
    {
        return true;
    }

    public function quote(ShipmentRequest $request): ShipmentQuote
    {
        return new ShipmentQuote(self::FEE_PER_PARCEL_MINOR * max(1, count($request->parcels)), $request->currency, 45);
    }

    public function create(ShipmentRequest $request): Shipment
    {
        $providerReference = 'FAKE-'.strtoupper(substr(hash('sha256', $request->reference), 0, 12));

        return $this->shipments[$providerReference] ??= new Shipment($request->reference, $providerReference, 'booked', null, $this->quote($request)->feeMinor, $request->currency);
    }

    public function track(string $providerReference): Shipment
    {
        return $this->shipments[$providerReference] ?? throw new CourierUnavailable(404, 'shipment_not_found', __('marketplace.errors.shipment_not_found'));
    }

    public function cancel(string $providerReference): Shipment
    {
        $shipment = $this->track($providerReference);

        return $this->shipments[$providerReference] = new Shipment($shipment->reference, $providerReference, 'cancelled', $shipment->trackingUrl, $shipment->feeMinor, $shipment->currency);
    }
}
