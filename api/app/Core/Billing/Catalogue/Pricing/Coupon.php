<?php

namespace App\Core\Billing\Catalogue\Pricing;

/**
 * SUB-01: a coupon already checked as usable (not expired, not used up).
 * A percent coupon takes a share of the lines it applies to; a fixed coupon
 * takes an amount in one currency off them, once per invoice, never more
 * than those lines cost. Empty module and bundle lists apply to every line.
 */
final class Coupon
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    /**
     * @param  list<string>  $modules
     * @param  list<string>  $bundles
     */
    public function __construct(
        public readonly string $code,
        public readonly string $kind,
        public readonly ?int $percent = null,
        public readonly ?int $amount = null,
        public readonly ?string $currency = null,
        public readonly array $modules = [],
        public readonly array $bundles = [],
    ) {}

    public function appliesToAll(): bool
    {
        return $this->modules === [] && $this->bundles === [];
    }
}
