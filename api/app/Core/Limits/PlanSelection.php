<?php

namespace App\Core\Limits;

/**
 * What the tenant's plan sells, as a catalogue quote request (ADR 013):
 * the country, the catalogue module keys, the quantities per quote unit
 * and the bundle. Used only to price extra units (LIM-02) with the
 * catalogue's PriceCalculator.
 */
final class PlanSelection
{
    /**
     * @param  list<string>  $modules
     * @param  array<string, int>  $quantities
     */
    public function __construct(
        public readonly string $country,
        public readonly array $modules,
        public readonly array $quantities = [],
        public readonly ?string $bundle = null,
    ) {}
}
