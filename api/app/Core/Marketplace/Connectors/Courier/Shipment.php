<?php

namespace App\Core\Marketplace\Connectors\Courier;

/** MKT-04: a booked delivery as the courier reports it. */
final class Shipment
{
    public const STATUSES = ['booked', 'picked_up', 'in_transit', 'delivered', 'cancelled', 'failed'];

    public function __construct(
        public readonly string $reference,
        public readonly string $providerReference,
        public readonly string $status,
        public readonly ?string $trackingUrl = null,
        public readonly ?int $feeMinor = null,
        public readonly ?string $currency = null,
    ) {}
}
