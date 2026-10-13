<?php

namespace App\Core\Onboarding\Trial;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Models\TenantModule;

/**
 * ONB-04 until billing exists: nothing is paid for, so once the trial has
 * ended no optional module is covered and each active one is switched off
 * (RBAC-08; its data is kept). Audited through TenantModule.
 */
class OnboardingTrialExpiry implements TrialExpiry
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function expire(): void
    {
        $installed = array_values(array_diff($this->modules->registered(), [ModuleRegistry::CORE]));

        TenantModule::query()->where('status', TenantModule::ACTIVE)->whereIn('module', $installed)->pluck('module')
            ->each(fn (string $module) => $this->modules->deactivate($module));
    }
}
