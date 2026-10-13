<?php

namespace App\Core\Onboarding\Trial;

use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Rbac\Models\TenantModule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ONB-04 until billing exists: the trial is the onboarding record's
 * `trial_ends_at` (config onboarding.trial_days from the first start), and
 * starting it activates the selected modules this installation has
 * (RBAC-08); modules selected but not installed yet stay in the selection
 * only. During the trial, modules dropped from the selection are switched
 * off again (their data is kept).
 */
class OnboardingTrial implements TrialStarter, TrialStatusProvider
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function start(TrialSelection $selection): TrialStatus
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($selection) {
            $state = OnboardingState::locked();
            $installed = array_values(array_diff($this->modules->registered(), [ModuleRegistry::CORE]));
            $wanted = array_values(array_intersect($selection->modules, $installed));

            foreach ($wanted as $module) {
                if (! $this->modules->isActive($module)) {
                    $this->modules->activate($module);
                }
            }

            if ($state->trial_started_at === null || $state->trial_ends_at?->isFuture()) {
                $dropped = TenantModule::query()->where('status', TenantModule::ACTIVE)
                    ->whereIn('module', array_diff($installed, $wanted))->pluck('module');
                $dropped->each(fn (string $module) => $this->modules->deactivate($module));
            }

            if ($state->trial_started_at === null) {
                $state->trial_started_at = now();
                $state->trial_ends_at = now()->addDays((int) config('onboarding.trial_days', 14));
            }

            $state->selection = $selection->toArray();
            $state->save();

            return TrialStatus::until($state->trial_ends_at);
        });
    }

    public function status(): TrialStatus
    {
        return TrialStatus::until(OnboardingState::query()->first()?->trial_ends_at);
    }
}
