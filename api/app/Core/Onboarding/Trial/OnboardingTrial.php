<?php

namespace App\Core\Onboarding\Trial;

use App\Core\Billing\Catalogue\Catalogue;
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
 * off again (their data is kept). After the trial has ended, starting is
 * refused (409 `trial_ended`): only billing switches modules on then.
 */
class OnboardingTrial implements TrialStarter, TrialStatusProvider
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Catalogue $catalogue,
    ) {}

    public function start(TrialSelection $selection): TrialStatus
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($selection) {
            $state = OnboardingState::locked();

            // A trial runs once: after it ends only billing switches modules on.
            if ($state->trial_ends_at !== null && ! $state->trial_ends_at->isFuture()) {
                throw TrialEnded::make();
            }

            $installed = array_values(array_diff($this->modules->registered(), [ModuleRegistry::CORE]));
            // A module may be sold under other catalogue keys (ModuleRegistry::soldAs).
            $wanted = array_values(array_intersect($this->modules->coveredBy($this->covered($selection)), $installed));

            foreach ($wanted as $module) {
                if (! $this->modules->isActive($module)) {
                    $this->modules->activate($module);
                }
            }

            TenantModule::query()->where('status', TenantModule::ACTIVE)
                ->whereIn('module', array_diff($installed, $wanted))->pluck('module')
                ->each(fn (string $module) => $this->modules->deactivate($module));

            if ($state->trial_started_at === null) {
                $state->trial_started_at = now();
                $state->trial_ends_at = now()->addDays((int) config('onboarding.trial_days', 14));
            }

            $state->selection = $selection->toArray();
            $state->save();

            return TrialStatus::until($state->trial_ends_at);
        });
    }

    /** The selected modules and, with a bundle, the bundle's modules from today's catalogue. */
    private function covered(TrialSelection $selection): array
    {
        $bundle = $selection->bundle === null ? null : $this->catalogue->snapshot($selection->country)->bundle($selection->bundle);

        return array_values(array_unique([...$selection->modules, ...($bundle?->modules ?? [])]));
    }

    public function status(): TrialStatus
    {
        return TrialStatus::until(OnboardingState::query()->first()?->trial_ends_at);
    }
}
