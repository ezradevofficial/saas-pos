<?php

namespace App\Core\Platform\Http\Requests;

/** Confirm two-factor enrolment with a code from the authenticator app. */
class PlatformConfirmTotpRequest extends PlatformRequest
{
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:16']];
    }
}
