<?php

namespace App\Core\Platform\SupportAccess\Http\Requests;

/**
 * RBAC-13b: approve a support request (`core.support_access.manage`).
 * `include_sensitive` (payroll, HR-sensitive and payment data) must be
 * answered either way and is recorded; `mode` may narrow write to read.
 */
class ApproveSupportAccessRequest extends SupportAccessViewRequest
{
    protected string $permission = 'core.support_access.manage';

    public function rules(): array
    {
        return [
            'include_sensitive' => ['required', 'boolean'],
            'mode' => ['sometimes', 'nullable', 'string', 'in:read,write'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
