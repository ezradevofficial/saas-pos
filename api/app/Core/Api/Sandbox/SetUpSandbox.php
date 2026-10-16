<?php

namespace App\Core\Api\Sandbox;

use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Sample\SampleData;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Events\TenantProvisioned;
use App\Core\Tenancy\TenantContext;

/**
 * API-05: a new sandbox tenant (sign-up with `sandbox: true`) gets every
 * module switched on without a subscription or trial, and the Phase 6
 * sample data (ONB-06) loaded by its owner. Its onboarding is done: there
 * is no plan to choose. Runs inside the sign-up transaction, after the
 * roles and the onboarding record (listeners registered earlier).
 */
class SetUpSandbox
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly SampleData $sample,
    ) {}

    public function handle(TenantProvisioned $event): void
    {
        if (! $event->tenant->is_sandbox) {
            return;
        }

        $this->tenants->run($event->tenant->id, function () use ($event) {
            foreach ($this->modules->registered() as $module) {
                if ($module !== ModuleRegistry::CORE) {
                    $this->modules->activate($module);
                }
            }

            $state = OnboardingState::current();
            $state->forceFill(['stage' => OnboardingState::STAGE_DONE, 'completed_at' => now()])->save();

            $this->sample->load($event->owner);
        });
    }
}
