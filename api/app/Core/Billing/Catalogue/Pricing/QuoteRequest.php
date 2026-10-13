<?php

namespace App\Core\Billing\Catalogue\Pricing;

/**
 * What a customer picks (ONB-03): modules, how many of each unit
 * (outlet, device, employee, company, online_store, warehouse), monthly or
 * annual, and optionally a bundle and a coupon.
 *
 * `device` is the total number of POS devices: the ones the POS includes
 * per outlet are not charged again.
 */
final class QuoteRequest
{
    public const MONTHLY = 'monthly';

    public const ANNUAL = 'annual';

    public const CYCLES = [self::MONTHLY, self::ANNUAL];

    /**
     * @param  list<string>  $modules
     * @param  array<string, int>  $quantities
     */
    public function __construct(
        public readonly array $modules,
        public readonly array $quantities = [],
        public readonly string $cycle = self::MONTHLY,
        public readonly ?string $bundle = null,
        public readonly ?Coupon $coupon = null,
    ) {}

    /** How many of $unit; a tenant is always one. */
    public function quantity(string $unit, int $default = 1): int
    {
        if ($unit === 'tenant') {
            return 1;
        }

        return max(0, (int) ($this->quantities[$unit] ?? $default));
    }
}
