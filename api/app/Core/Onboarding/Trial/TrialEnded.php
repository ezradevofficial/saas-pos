<?php

namespace App\Core\Onboarding\Trial;

use App\Core\Http\ApiException;

/** ONB-04: the trial has ended; modules are switched on by a subscription only. */
final class TrialEnded
{
    public static function make(): ApiException
    {
        return new ApiException(409, 'trial_ended', __('onboarding.errors.trial_ended'));
    }
}
