<?php

namespace App\Core\Api\Sandbox;

use App\Core\Identity\Models\User;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Sample\SampleData;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * API-05 (review): a sandbox's modules and sample data (Phase 6 SampleData),
 * set up by a queued job once its owner verified their email or phone, so
 * an unverified sign-up costs nothing. Its onboarding is done: there is no
 * plan to choose. Idempotent: a set-up sandbox is left as it is.
 */
class SetUpSandboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $tenantId,
        public string $ownerId,
    ) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(ModuleRegistry $modules, SampleData $sample, SandboxCaps $caps): void
    {
        $tenant = Tenant::query()->whereKey($this->tenantId)->first();
        $state = OnboardingState::current();

        if ($tenant === null || ! $tenant->is_sandbox || $state->stage === OnboardingState::STAGE_DONE) {
            return;
        }

        foreach ($modules->registered() as $module) {
            if ($module !== ModuleRegistry::CORE) {
                $modules->activate($module);
            }
        }

        $state->forceFill(['stage' => OnboardingState::STAGE_DONE, 'completed_at' => now()])->save();
        $sample->load(User::query()->findOrFail($this->ownerId));
        // The records cap counts what the developer creates, not the sample.
        $caps->restartRecordCount();
    }
}
