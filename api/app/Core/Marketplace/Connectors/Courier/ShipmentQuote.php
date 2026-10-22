<?php

namespace App\Core\Marketplace\Connectors\Courier;

/** MKT-04: a courier's price for a delivery, in minor units, and its estimate in minutes. */
final class ShipmentQuote
{
    public function __construct(
        public readonly int $feeMinor,
        public readonly string $currency,
        public readonly ?int $etaMinutes = null,
    ) {}
}
