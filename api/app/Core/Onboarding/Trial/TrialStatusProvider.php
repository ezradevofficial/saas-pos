<?php

namespace App\Core\Onboarding\Trial;

/**
 * ONB-04: the trial state and days left the app shows on every screen
 * (GET onboarding/trial). Billing replaces the default (OnboardingTrial)
 * with its subscription's state.
 */
interface TrialStatusProvider
{
    public function status(): TrialStatus;
}
