<?php

namespace App\Core\Onboarding\Trial;

/**
 * ONB-04: starts the current tenant's free trial of a selection, with no
 * payment details. Billing (SUB-02) implements it to open the trial
 * subscription; until then OnboardingTrial activates the selected modules
 * and records the end date on the onboarding record.
 *
 * Runs in the current tenant's context, inside the caller's transaction.
 * Calling it again while the trial runs changes the selection without
 * moving the end date.
 */
interface TrialStarter
{
    public function start(TrialSelection $selection): TrialStatus;
}
