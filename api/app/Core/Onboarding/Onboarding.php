<?php

namespace App\Core\Onboarding;

use App\Core\Audit\Auditor;
use App\Core\Billing\Catalogue\Catalogue;
use App\Core\Billing\Catalogue\Http\Controllers\PublicCatalogueController;
use App\Core\Http\ApiException;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Trial\TrialEnded;
use App\Core\Onboarding\Trial\TrialSelection;
use App\Core\Onboarding\Trial\TrialStarter;
use App\Core\Onboarding\Trial\TrialStatus;
use App\Core\Onboarding\Trial\TrialStatusProvider;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\RoleTemplates;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Definitions\FlowDefinitions;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The self-onboarding steps of the current tenant (ONB-02..ONB-05):
 * the business type and what it applies, the module selection that
 * starts the trial (through TrialStarter, which billing implements), and
 * the setup wizard's progress. Every change is audited (AUD-01).
 */
class Onboarding
{
    /** ONB-05: the setup wizard's steps, in order. */
    public const STEPS = ['company', 'currencies', 'branches', 'taxes', 'payment_methods', 'items', 'users', 'devices'];

    public const STEP_DONE = 'done';

    public const STEP_SKIPPED = 'skipped';

    public const STEP_TODO = 'todo';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly BusinessTypes $types,
        private readonly TrialStarter $trials,
        private readonly TrialStatusProvider $statuses,
        private readonly Catalogue $catalogue,
        private readonly RoleTemplates $roleTemplates,
        private readonly DocumentTypeRegistry $documentTypes,
        private readonly FlowDefinitions $flows,
        private readonly Auditor $auditor,
        private readonly ModuleRegistry $modules,
    ) {}

    /** The country the tenant signed up in (its first company's). */
    public function country(): string
    {
        return (string) (Company::query()->orderBy('created_at')->orderBy('id')->value('country') ?? 'KE');
    }

    /**
     * ONB-02: apply a business type: the POS mode on the tenant
     * (settings.pos_mode), its role templates, its default flows, and the
     * picker's suggested modules and quantities. $outlets, when given,
     * replaces the suggested number of outlets.
     */
    public function chooseBusinessType(string $key, ?int $outlets = null): OnboardingState
    {
        $preset = $this->types->find($key) ?? throw new InvalidArgumentException("Unknown business type [{$key}].");

        return $this->transaction(function () use ($preset, $outlets) {
            $state = OnboardingState::locked();
            $tenant = Tenant::query()->whereKey($this->tenants->require())->lockForUpdate()->firstOrFail();

            $settings = $tenant->settings ?? [];
            $before = $settings['pos_mode'] ?? null;
            $settings['pos_mode'] = $preset['pos_mode'];
            $tenant->settings = $settings;
            $tenant->save();

            if ($before !== $preset['pos_mode']) {
                $this->auditor->record('core.settings.update', $tenant, ['pos_mode' => $before], ['pos_mode' => $preset['pos_mode']]);
            }

            $roles = $this->roleTemplates->ensure($tenant, $preset['role_templates']);
            $flows = $this->adoptFlows($preset['flows']);
            $features = $this->applyFeatures($preset['features']);

            $quantities = $preset['quantities'];
            if ($outlets !== null && isset($quantities['outlet'])) {
                $quantities['outlet'] = $outlets;
                // POS includes two devices an outlet (concept note 8.4); never suggest fewer.
                $quantities['device'] = max($quantities['device'] ?? 0, 2 * $outlets);
            }

            $state->fill([
                'business_type' => $preset['key'],
                'applied' => ['pos_mode' => $preset['pos_mode'], 'role_templates' => $roles->keys()->values()->all(), 'flows' => $flows, 'features' => $features],
                // Until the trial starts, the picker opens on the preset's suggestion.
                'selection' => $state->trial_started_at === null
                    ? ['country' => $this->country(), 'modules' => $preset['modules'], 'quantities' => $quantities, 'cycle' => 'monthly', 'bundle' => null, 'business_type' => $preset['key']]
                    : [...$state->selection, 'business_type' => $preset['key']],
                'stage' => $this->later($state->stage, OnboardingState::STAGE_MODULES),
            ])->save();

            return $state;
        });
    }

    /**
     * The modules that belong to a business type's till mode (restaurant and
     * bar: the Restaurant module) follow the type: this type's are switched
     * on, those of the other types off (their data is kept, SUB-07).
     *
     * @param  list<string>  $wanted
     * @return list<string> the modules now on for this type
     */
    private function applyFeatures(array $wanted): array
    {
        $on = [];

        foreach ($this->types->featureModules() as $module) {
            if (! in_array($module, $this->modules->registered(), true)) {
                continue;
            }

            if (in_array($module, $wanted, true)) {
                $this->modules->isActive($module) || $this->modules->activate($module);
                $on[] = $module;
            } elseif ($this->modules->isActive($module)) {
                $this->modules->deactivate($module);
            }
        }

        return $on;
    }

    /**
     * ONB-03, ONB-04: the modules, quantities and cycle chosen; priced
     * against the catalogue (a 422 such as `addon_requires_bundle` when the
     * selection cannot be sold), then the trial starts (TrialStarter).
     *
     * @param  array{modules: list<string>, quantities?: array<string, int>, cycle: string, bundle?: ?string}  $input
     * @return array{state: OnboardingState, trial: TrialStatus, quote: array<string, mixed>}
     */
    public function chooseModules(array $input): array
    {
        // ONB-04: a selection starts or changes a trial only before it ends.
        if (! in_array($this->statuses->status()->state, [TrialStatus::NONE, TrialStatus::TRIAL], true)) {
            throw TrialEnded::make();
        }

        $country = $this->country();
        $quantities = array_map('intval', $input['quantities'] ?? []);
        $quote = PublicCatalogueController::price($this->catalogue, [...$input, 'country' => $country, 'quantities' => $quantities, 'coupon' => null]);

        // Enterprise is priced by sales (concept note 8.4): no self-service trial of it.
        if ($quote['quoted'] ?? false) {
            $message = __('onboarding.errors.bundle_quoted');

            throw new ApiException(422, 'bundle_quoted', $message, ['bundle' => [$message]]);
        }

        return $this->transaction(function () use ($input, $country, $quantities, $quote) {
            $state = OnboardingState::locked();
            $selection = new TrialSelection($country, array_values($input['modules']), $quantities, $input['cycle'], $input['bundle'] ?? null, $state->business_type);
            $trial = $this->trials->start($selection);

            $state = OnboardingState::locked();
            $state->fill([
                'selection' => $selection->toArray(),
                'stage' => $this->later($state->stage, OnboardingState::STAGE_SETUP),
            ])->save();

            return ['state' => $state, 'trial' => $trial, 'quote' => $quote];
        });
    }

    /** ONB-05: mark a wizard step done, skipped, or to do again. */
    public function markStep(string $step, string $status): OnboardingState
    {
        if (! in_array($step, self::STEPS, true) || ! in_array($status, [self::STEP_DONE, self::STEP_SKIPPED, self::STEP_TODO], true)) {
            throw new InvalidArgumentException("Unknown step [{$step}] or status [{$status}].");
        }

        return $this->transaction(function () use ($step, $status) {
            $state = OnboardingState::locked();
            $steps = $state->steps ?? [];

            if ($status === self::STEP_TODO) {
                unset($steps[$step]);
            } else {
                $steps[$step] = $status;
            }

            $state->steps = $steps;
            $finished = array_diff(self::STEPS, array_keys($steps)) === [];
            if ($finished && $state->stage === OnboardingState::STAGE_SETUP) {
                $state->stage = OnboardingState::STAGE_DONE;
                $state->completed_at = now();
            }
            $state->save();

            return $state;
        });
    }

    /**
     * WF-02: publish the default flow of each document type the business
     * type lists (as the engine would on first use); types this
     * installation does not have are skipped.
     *
     * @param  list<string>  $keys
     * @return list<string> the types whose flow is in force
     */
    private function adoptFlows(array $keys): array
    {
        $company = Company::query()->orderBy('created_at')->orderBy('id')->first();
        $adopted = [];

        foreach ($keys as $key) {
            $type = $this->documentTypes->find($key);

            if ($type !== null && $this->flows->adoptDefault($type, $company) !== null) {
                $adopted[] = $key;
            }
        }

        return $adopted;
    }

    private function later(string $current, string $next): string
    {
        $stages = OnboardingState::STAGES;

        return array_search($next, $stages, true) > array_search($current, $stages, true) ? $next : $current;
    }

    private function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
