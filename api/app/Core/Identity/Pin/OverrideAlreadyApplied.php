<?php

namespace App\Core\Identity\Pin;

use App\Core\Http\ApiException;

/**
 * AUTH-08: the override was already used for this very action (same
 * device, permission and record), for example a sale uploaded twice. Not a
 * fresh approval: the caller treats the action as already recorded and
 * answers as for the first upload. `override` is the earlier result.
 */
class OverrideAlreadyApplied extends ApiException
{
    public function __construct(public readonly VerifiedOverride $override)
    {
        parent::__construct(409, 'override_already_applied', __('auth.override.already_applied'));
    }
}
