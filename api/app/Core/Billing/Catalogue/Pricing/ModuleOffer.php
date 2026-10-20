<?php

namespace App\Core\Billing\Catalogue\Pricing;

/**
 * SUB-01: one module of the catalogue, with its current price in one
 * country (minor units a month, per unit). A module without a price in the
 * country has a null amount and cannot be quoted there.
 */
final class ModuleOffer
{
    public const KIND_MODULE = 'module';

    public const KIND_EXTRA = 'extra';

    public const KIND_ADDON = 'addon';

    public const KINDS = [self::KIND_MODULE, self::KIND_EXTRA, self::KIND_ADDON];

    /** Units a module is priced by (concept note 8.2). Warehouses are priced as outlets in 8.4. */
    public const UNITS = ['outlet', 'device', 'warehouse', 'online_store', 'company', 'employee', 'tenant'];

    /**
     * @param  array<string, int>  $includes  units of another entry included per unit of this one
     */
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $unit,
        public readonly ?int $amount,
        public readonly ?int $minimum = null,
        public readonly array $includes = [],
        public readonly ?string $requires = null,
        public readonly ?string $priceId = null,
        // ADM-02: priced by the tenant's Enterprise deal, not the catalogue.
        public readonly bool $deal = false,
    ) {}
}
