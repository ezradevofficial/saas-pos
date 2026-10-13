<?php

namespace App\Core\Onboarding\Console;

use App\Core\Audit\AuditContext;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Trial\TrialExpiry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ONB-04: trials past their end. For each tenant (DueTenants, never the
 * owner connection), in its own context and transaction, TrialExpiry
 * switches off what is not covered (billing rebinds it), and the trial is
 * marked expired so it is handled once. One tenant's failure never stops
 * the others.
 */
class ExpireTrialsCommand extends Command
{
    protected $signature = 'onboarding:expire-trials';

    protected $description = 'Switch off the modules of ended trials (ONB-04)';

    public function handle(DueTenants $due, TenantContext $tenants, AuditContext $audit): int
    {
        $count = 0;

        foreach ($due->withExpiredTrials(now()) as $tenantId) {
            $audit->reset();

            try {
                $tenants->run($tenantId, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () {
                    $state = OnboardingState::locked();

                    if ($state->trial_expired_at !== null || $state->trial_ends_at === null || $state->trial_ends_at->isFuture()) {
                        return;
                    }

                    app(TrialExpiry::class)->expire();
                    $state->trial_expired_at = now();
                    $state->save();
                }));
                $count++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->components->info("Ended trials handled: {$count}.");

        return self::SUCCESS;
    }
}
