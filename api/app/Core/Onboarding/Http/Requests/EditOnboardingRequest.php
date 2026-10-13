<?php

namespace App\Core\Onboarding\Http\Requests;

/** ONB-05..ONB-07: sample data load and remove, dismissing the checklist (`core.onboarding.edit`, tenant scope). */
class EditOnboardingRequest extends OnboardingRequest
{
    protected string $permission = 'core.onboarding.edit';
}
