<?php

namespace App\Core\Onboarding\Http\Requests;

use App\Core\Onboarding\BusinessTypes;
use Illuminate\Validation\Rule;

/** ONB-02: a business type from the presets and, optionally, the number of outlets. */
class ChooseBusinessTypeRequest extends EditOnboardingRequest
{
    protected string $permission = 'core.onboarding.modules';

    public function rules(): array
    {
        return [
            'business_type' => ['required', 'string', Rule::in(app(BusinessTypes::class)->keys())],
            'outlets' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'business_type' => __('onboarding.attributes.business_type'),
            'outlets' => __('onboarding.attributes.outlets'),
        ];
    }
}
