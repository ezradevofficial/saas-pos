<?php

namespace App\Core\Billing\Catalogue\Pricing;

/**
 * SUB-01: a bundle (Starter, Business, Enterprise) with its current price
 * in one country. The price covers `includedOutlets` outlets of every
 * included module, and one unit of an included module priced per company,
 * online store or tenant. More outlets cost `extraOutletAmount` each, or
 * cannot be covered when it is null. A quoted bundle is never priced.
 */
final class BundleOffer
{
    /**
     * @param  list<string>  $modules
     * @param  list<string>  $addons  add-on keys that may be bought with this bundle
     */
    public function __construct(
        public readonly string $key,
        public readonly array $modules,
        public readonly array $addons,
        public readonly bool $quoted,
        public readonly ?int $amount,
        public readonly ?int $extraOutletAmount = null,
        public readonly int $includedOutlets = 1,
        public readonly ?string $priceId = null,
    ) {}
}
