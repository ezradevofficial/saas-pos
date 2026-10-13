<?php

namespace App\Core\Onboarding;

use App\Core\Identity\Events\InvitationAccepted;
use App\Core\Onboarding\Checklist\Checklist;
use App\Core\Onboarding\Console\ExpireTrialsCommand;
use App\Core\Onboarding\Console\HandoverReportCommand;
use App\Core\Onboarding\Console\PrunePendingSignUpsCommand;
use App\Core\Onboarding\Listeners\CreateOnboardingState;
use App\Core\Onboarding\Listeners\HandOverOwnership;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Sample\SampleDependents;
use App\Core\Onboarding\Trial\OnboardingTrial;
use App\Core\Onboarding\Trial\OnboardingTrialExpiry;
use App\Core\Onboarding\Trial\TrialExpiry;
use App\Core\Onboarding\Trial\TrialStarter;
use App\Core\Onboarding\Trial\TrialStatusProvider;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Events\TenantProvisioned;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Self-onboarding (ONB-01..ONB-08, docs/adr/017-onboarding.md): the
 * onboarding record of each new tenant, the default trial (billing binds
 * its own TrialStarter and TrialStatusProvider), the checklist and sample
 * data registries modules add to, and the assisted handover.
 */
class OnboardingServiceProvider extends ServiceProvider
{
    /** ONB-01: sign-ups per email or phone number, an hour. */
    public const SIGN_UP_DESTINATION_LIMITER = 'sign-up-destination';

    /** ONB-01: sign-ups across the platform, an hour. */
    public const SIGN_UP_GLOBAL_LIMITER = 'sign-up-global';

    public function register(): void
    {
        $this->app->singleton(BusinessTypes::class);
        $this->app->singleton(Checklist::class);
        $this->app->singleton(SampleDependents::class);
        // ONB-04: until billing (SUB-02) binds its own.
        $this->app->bindIf(TrialStarter::class, OnboardingTrial::class);
        $this->app->bindIf(TrialStatusProvider::class, OnboardingTrial::class);
        $this->app->bindIf(TrialExpiry::class, OnboardingTrialExpiry::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireTrialsCommand::class, PrunePendingSignUpsCommand::class, HandoverReportCommand::class]);
        }

        // ONB-01: mass sign-up. Per destination (the email or phone digits), and one global budget.
        RateLimiter::for(self::SIGN_UP_DESTINATION_LIMITER, function (Request $request) {
            $email = $request->input('email');
            $phone = $request->input('phone');
            $destination = is_string($email) && trim($email) !== '' ? Str::lower(trim($email)) : preg_replace('/\D+/', '', is_string($phone) ? $phone : '');

            return Limit::perHour((int) config('onboarding.sign_ups_per_destination_per_hour', 3))->by('sign-up|'.($destination ?: 'none'));
        });
        RateLimiter::for(self::SIGN_UP_GLOBAL_LIMITER, fn () => Limit::perHour((int) config('onboarding.sign_ups_per_hour', 200))->by('sign-up|all'));

        Event::listen(TenantProvisioned::class, CreateOnboardingState::class);
        Event::listen(InvitationAccepted::class, HandOverOwnership::class);

        // ONB-07: core's checklist items; modules add theirs (POS: the first sale).
        $checklist = $this->app->make(Checklist::class);
        $checklist->register('company', ModuleRegistry::CORE, fn (OnboardingState $state) => ($state->steps['company'] ?? null) === Onboarding::STEP_DONE
            || SetupSteps::firstCompany()?->tax_id !== null, 10);
        $checklist->register('tax_id', ModuleRegistry::CORE, fn () => SetupSteps::firstCompany()?->tax_id !== null, 20);
        $checklist->register('item', ModuleRegistry::CORE, fn () => SetupSteps::hasRealItem(), 30);
        $checklist->register('till', ModuleRegistry::CORE, fn () => SetupSteps::hasPairedTill(), 40);

        // ONB-06: real records core keeps that may use a sample one.
        $this->app->make(SampleDependents::class)->register(function (array $records): array {
            $found = [];
            $items = $records['item'] ?? [];
            $categories = $records['item_category'] ?? [];

            foreach (DB::table('items')->whereIn('category_id', $categories)->whereNotIn('id', $items)->whereNull('archived_at')->pluck('id') as $id) {
                $found[] = ['type' => 'item_category', 'id' => (string) $id, 'used_by' => 'item'];
            }
            foreach (DB::table('item_categories')->whereIn('parent_id', $categories)->whereNotIn('id', $categories)->whereNull('archived_at')->pluck('id') as $id) {
                $found[] = ['type' => 'item_category', 'id' => (string) $id, 'used_by' => 'item_category'];
            }
            $prices = DB::table('item_prices')->whereNotIn('id', $records['item_price'] ?? [])->whereNull('archived_at')
                ->where(fn ($query) => $query->whereIn('item_id', $items)->orWhereIn('price_list_id', $records['price_list'] ?? []));
            foreach ($prices->pluck('id') as $id) {
                $found[] = ['type' => 'item', 'id' => (string) $id, 'used_by' => 'item_price'];
            }
            foreach (DB::table('parties')->whereIn('price_list_id', $records['price_list'] ?? [])->pluck('id') as $id) {
                $found[] = ['type' => 'price_list', 'id' => (string) $id, 'used_by' => 'party'];
            }
            foreach (DB::table('credit_limit_changes')->whereIn('party_id', $records['party'] ?? [])->pluck('id') as $id) {
                $found[] = ['type' => 'party', 'id' => (string) $id, 'used_by' => 'credit_limit_change'];
            }

            // CF-01, CF-04: lookup custom fields (any entity, custom form records and lines) naming a sample record.
            $ids = array_merge($items, $categories, $records['party'] ?? [], $records['price_list'] ?? []);
            if ($ids !== []) {
                $pattern = '('.implode('|', array_map(fn (string $id) => preg_quote($id, '/'), $ids)).')';
                foreach (['items' => $items, 'parties' => $records['party'] ?? [], 'custom_form_records' => [], 'custom_form_lines' => []] as $table => $own) {
                    foreach (DB::table($table)->whereNotIn('id', $own)->whereRaw('custom::text ~ ?', [$pattern])->pluck('id') as $id) {
                        $found[] = ['type' => 'custom_field', 'id' => (string) $id, 'used_by' => $table];
                    }
                }
            }

            return $found;
        });
    }
}
