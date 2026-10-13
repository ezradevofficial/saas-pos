<?php

namespace App\Core\Onboarding\Http\Requests;

use App\Core\Onboarding\Onboarding;
use Illuminate\Validation\Rule;

/** ONB-05: a setup step marked done, skipped, or to do again. */
class MarkStepRequest extends EditOnboardingRequest
{
    public function rules(): array
    {
        return [
            'step' => ['required', 'string', Rule::in(Onboarding::STEPS)],
            'status' => ['required', 'string', Rule::in([Onboarding::STEP_DONE, Onboarding::STEP_SKIPPED, Onboarding::STEP_TODO])],
        ];
    }

    public function attributes(): array
    {
        return [
            'step' => __('onboarding.attributes.step'),
            'status' => __('onboarding.attributes.status'),
        ];
    }
}
