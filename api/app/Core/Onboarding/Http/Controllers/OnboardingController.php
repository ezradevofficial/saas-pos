<?php

namespace App\Core\Onboarding\Http\Controllers;

use App\Core\Onboarding\BusinessTypes;
use App\Core\Onboarding\Checklist\Checklist;
use App\Core\Onboarding\Http\Requests\ChooseBusinessTypeRequest;
use App\Core\Onboarding\Http\Requests\ChooseModulesRequest;
use App\Core\Onboarding\Http\Requests\EditOnboardingRequest;
use App\Core\Onboarding\Http\Requests\MarkStepRequest;
use App\Core\Onboarding\Http\Requests\OnboardingRequest;
use App\Core\Onboarding\Http\Requests\TrialStatusRequest;
use App\Core\Onboarding\Models\OnboardingState;
use App\Core\Onboarding\Onboarding;
use App\Core\Onboarding\Sample\SampleData;
use App\Core\Onboarding\SetupSteps;
use App\Core\Onboarding\Trial\TrialStatusProvider;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * ONB-02..ONB-07 for the signed-in tenant: the onboarding state, the
 * business type, the modules that start the trial, the setup wizard's
 * steps, sample data and the getting-started checklist.
 */
class OnboardingController
{
    public function __construct(
        private readonly Onboarding $onboarding,
        private readonly BusinessTypes $types,
        private readonly SetupSteps $steps,
        private readonly SampleData $sample,
        private readonly Checklist $checklist,
        private readonly TrialStatusProvider $trials,
        private readonly TenantContext $tenants,
    ) {}

    /** GET onboarding */
    public function show(OnboardingRequest $request): JsonResponse
    {
        return $this->respond(OnboardingState::current());
    }

    /** PUT onboarding/business */
    public function business(ChooseBusinessTypeRequest $request): JsonResponse
    {
        $outlets = $request->validated('outlets');

        return $this->respond($this->onboarding->chooseBusinessType($request->validated('business_type'), $outlets === null ? null : (int) $outlets));
    }

    /** PUT onboarding/modules: the selection, then the trial (TrialStarter). */
    public function modules(ChooseModulesRequest $request): JsonResponse
    {
        ['state' => $state, 'quote' => $quote] = $this->onboarding->chooseModules($request->validated());

        return $this->respond($state, ['quote' => $quote]);
    }

    /** POST onboarding/steps */
    public function step(MarkStepRequest $request): JsonResponse
    {
        return $this->respond($this->onboarding->markStep($request->validated('step'), $request->validated('status')));
    }

    /** GET onboarding/trial: for every user (the banner). */
    public function trial(TrialStatusRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->trials->status()->toArray()]);
    }

    /** POST onboarding/sample-data */
    public function loadSample(EditOnboardingRequest $request): JsonResponse
    {
        return $this->respond($this->sample->load($request->user()), status: 201);
    }

    /** POST onboarding/sample-data/remove */
    public function removeSample(EditOnboardingRequest $request): JsonResponse
    {
        return $this->respond($this->sample->remove($request->user()));
    }

    /** GET onboarding/checklist */
    public function checklist(OnboardingRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->checklist->state()]);
    }

    /** POST onboarding/checklist/dismiss */
    public function dismissChecklist(EditOnboardingRequest $request): JsonResponse
    {
        $this->checklist->dismiss();

        return response()->json(['data' => $this->checklist->state()]);
    }

    private function respond(OnboardingState $state, array $meta = [], int $status = 200): JsonResponse
    {
        $tenant = Tenant::findOrFail($this->tenants->require());

        return response()->json(array_filter([
            'data' => [
                'stage' => $state->stage,
                'country' => $this->onboarding->country(),
                'business_name' => $tenant->name,
                'slug' => $tenant->slug,
                'business_type' => $state->business_type,
                'pos_mode' => ($tenant->settings ?? [])['pos_mode'] ?? null,
                'selection' => $state->selection,
                'applied' => $state->applied,
                'steps' => $this->steps->all($state),
                'trial' => $this->trials->status()->toArray(),
                'sample' => $this->sample->current(),
                'assisted' => $state->assisted === null ? null : ['handed_over' => ($state->assisted['handed_over_at'] ?? null) !== null],
                'completed_at' => $state->completed_at?->toIso8601String(),
                'business_types' => $this->types->present(),
                'module_groups' => $this->types->moduleGroups(),
            ],
            'meta' => $meta === [] ? null : $meta,
        ], fn ($value) => $value !== null), $status);
    }
}
