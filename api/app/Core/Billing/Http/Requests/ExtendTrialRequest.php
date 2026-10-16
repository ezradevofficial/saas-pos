<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** ONB-04, ADM-01c: staff (`platform.tenants.manage`) move a trial's end to a later date, at most a year ahead, with a reason. */
class ExtendTrialRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.tenants.manage';

    public function rules(): array
    {
        return [
            'trial_ends_at' => ['required', 'date', 'after:now', 'before:+1 year'],
            // ADM-01c: every staff action on a tenant says why.
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
