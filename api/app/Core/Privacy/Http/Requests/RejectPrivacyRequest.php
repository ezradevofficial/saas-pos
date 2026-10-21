<?php

namespace App\Core\Privacy\Http\Requests;

/** NFR-08: reject a request, saying why (the reason is kept with it). */
class RejectPrivacyRequest extends PrivacyRequestAction
{
    protected string $permission = 'core.privacy_request.approve';

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
