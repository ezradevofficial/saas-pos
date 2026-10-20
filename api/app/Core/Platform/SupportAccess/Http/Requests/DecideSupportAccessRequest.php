<?php

namespace App\Core\Platform\SupportAccess\Http\Requests;

/** RBAC-13b: deny a support request or revoke an approval (`core.support_access.manage`). */
class DecideSupportAccessRequest extends SupportAccessViewRequest
{
    protected string $permission = 'core.support_access.manage';

    public function rules(): array
    {
        return ['note' => ['sometimes', 'nullable', 'string', 'max:500']];
    }
}
