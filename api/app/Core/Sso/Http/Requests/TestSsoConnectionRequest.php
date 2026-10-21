<?php

namespace App\Core\Sso\Http\Requests;

/** AUTH-11: start a test sign-in with a connection; `origin` is where the browser comes back. */
class TestSsoConnectionRequest extends SsoManageRequest
{
    public function rules(): array
    {
        return ['origin' => ['nullable', 'string', 'max:255']];
    }
}
