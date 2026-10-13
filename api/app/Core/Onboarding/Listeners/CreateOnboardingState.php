<?php

namespace App\Core\Onboarding\Listeners;

use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Tenancy\Events\TenantProvisioned;
use App\Core\Tenancy\TenantContext;

/**
 * ONB-01: a new tenant starts onboarding at the business type. Runs inside
 * the sign-up transaction and the new tenant's context.
 */
class CreateOnboardingState
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(TenantProvisioned $event): void
    {
        $this->tenants->run($event->tenant->id, fn () => OnboardingState::current());
    }
}
