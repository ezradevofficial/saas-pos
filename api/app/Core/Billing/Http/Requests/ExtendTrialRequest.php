<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** ONB-04: staff (owner, billing) move a trial's end to a later date, at most a year ahead. */
class ExtendTrialRequest extends PlatformRequest
{
    protected ?string $ability = 'billing.manage';

    public function rules(): array
    {
        return ['trial_ends_at' => ['required', 'date', 'after:now', 'before:+1 year']];
    }
}
