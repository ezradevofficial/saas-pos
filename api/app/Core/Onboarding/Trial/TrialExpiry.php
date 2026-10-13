<?php

namespace App\Core\Onboarding\Trial;

/**
 * ONB-04: what happens to the current tenant when its trial has ended
 * (run by `onboarding:expire-trials`, in the tenant's context, once per
 * ended trial). The default (OnboardingTrialExpiry) switches off every
 * module not covered; billing (SUB-02) rebinds it to keep what the
 * subscription covers.
 */
interface TrialExpiry
{
    public function expire(): void;
}
