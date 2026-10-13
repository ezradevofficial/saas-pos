<?php

namespace App\Core\Onboarding\Trial;

/**
 * ONB-03, ONB-04: what a trial is started with: catalogue module keys,
 * quantities per unit (outlet, device, employee, company, online_store,
 * warehouse), the billing cycle and an optional bundle, for the tenant's
 * country. Billing (SUB-02) turns it into the subscription.
 */
final class TrialSelection
{
    /**
     * @param  list<string>  $modules
     * @param  array<string, int>  $quantities
     */
    public function __construct(
        public readonly string $country,
        public readonly array $modules,
        public readonly array $quantities,
        public readonly string $cycle,
        public readonly ?string $bundle = null,
        public readonly ?string $businessType = null,
    ) {}

    /** @return array{country: string, modules: list<string>, quantities: array<string, int>, cycle: string, bundle: ?string, business_type: ?string} */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'modules' => $this->modules,
            'quantities' => $this->quantities,
            'cycle' => $this->cycle,
            'bundle' => $this->bundle,
            'business_type' => $this->businessType,
        ];
    }
}
