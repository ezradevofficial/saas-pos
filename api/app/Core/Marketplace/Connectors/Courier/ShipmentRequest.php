<?php

namespace App\Core\Marketplace\Connectors\Courier;

/**
 * MKT-04: a delivery to book. `reference` is ours (the order or delivery
 * id) and makes booking idempotent; weights are in grams; a cash on
 * delivery amount is in minor units of `currency`.
 *
 * @phpstan-type Place array{name: string, phone: string, address: string, latitude?: ?float, longitude?: ?float}
 */
final class ShipmentRequest
{
    /**
     * @param  array{name: string, phone: string, address: string, latitude?: ?float, longitude?: ?float}  $pickup
     * @param  array{name: string, phone: string, address: string, latitude?: ?float, longitude?: ?float}  $dropoff
     * @param  list<array{description: string, weight_grams: int}>  $parcels
     */
    public function __construct(
        public readonly string $reference,
        public readonly array $pickup,
        public readonly array $dropoff,
        public readonly array $parcels,
        public readonly string $currency,
        public readonly int $cashOnDeliveryMinor = 0,
    ) {}
}
