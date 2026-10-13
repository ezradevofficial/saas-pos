<?php

namespace App\Core\Billing\Console;

use App\Core\Billing\Catalogue\Models\CatalogueModule;
use App\Core\Billing\Catalogue\Pricing\ModuleOffer;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Subscriptions\BillingTrial;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Http\ApiException;
use App\Core\Limits\UsageCounter;
use App\Core\Limits\Units;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\DueTenants;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SUB-02, ONB-04: a one-off deploy step for tenants created before
 * billing. Each active tenant with no subscription gets a trial one,
 * planned from its onboarding selection, or else from its active modules
 * the catalogue sells (with its outlets, devices and employees counted).
 *
 * Tenant ids come from DueTenants (a security-definer function on the
 * runtime connection, ADR 002); each tenant is handled in its own
 * context (TenantContext::run); never the owner connection. Idempotent:
 * a tenant that has a subscription is left alone, and the check runs
 * under an advisory lock per tenant.
 */
class BackfillSubscriptions extends Command
{
    protected $signature = 'billing:backfill-subscriptions';

    protected $description = 'Start a trial subscription for each tenant that has none (one-off, after deploying billing)';

    public function handle(DueTenants $due, TenantContext $tenants, Subscriptions $subscriptions, ModuleRegistry $modules, UsageCounter $usage): int
    {
        $operator = $due->operator();
        $started = $skipped = $failed = 0;

        foreach ($due->active() as $tenantId) {
            if ($tenantId === $operator) {
                continue;
            }

            try {
                $done = $tenants->run($tenantId, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($tenantId, $subscriptions, $modules, $usage) {
                    DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['billing-backfill:'.$tenantId]);

                    if (Subscription::query()->exists()) {
                        return false;
                    }

                    [$country, $plan] = $this->plan($modules, $usage);
                    $subscription = $subscriptions->startTrial($country, $plan);
                    BillingTrial::syncOnboarding($subscription);

                    return true;
                }));
            } catch (ApiException $e) {
                $failed++;
                $this->components->warn("Tenant {$tenantId}: {$e->getMessage()}");

                continue;
            }

            $done ? $started++ : $skipped++;
        }

        $this->components->info("{$started} trial subscriptions started, {$skipped} tenants already subscribed, {$failed} not started.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The country and the plan: the onboarding selection when there is
     * one, else the active modules the catalogue sells, monthly.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function plan(ModuleRegistry $modules, UsageCounter $usage): array
    {
        $selection = OnboardingState::query()->first()?->selection;
        $country = (string) ($selection['country'] ?? Company::query()->orderBy('created_at')->orderBy('id')->value('country') ?? 'KE');

        if (! empty($selection['modules']) || ! empty($selection['bundle'])) {
            return [$country, [
                'modules' => $selection['modules'] ?? [],
                'quantities' => $selection['quantities'] ?? [],
                'cycle' => $selection['cycle'] ?? 'monthly',
                'bundle' => $selection['bundle'] ?? null,
            ]];
        }

        $sold = CatalogueModule::on(PlatformDatabase::READ)->where('kind', ModuleOffer::KIND_MODULE)->pluck('key')->all();
        $quantities = [];

        foreach (Units::QUOTE_UNITS as $unit => $quoteUnit) {
            $count = $usage->count($unit);

            if ($count > 0 || $unit === Units::OUTLET) {
                $quantities[$quoteUnit] = max(1, $count);
            }
        }

        return [$country, [
            'modules' => array_values(array_intersect($modules->active(), $sold)),
            'quantities' => $quantities,
            'cycle' => 'monthly',
            'bundle' => null,
        ]];
    }
}
