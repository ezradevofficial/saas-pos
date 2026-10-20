<?php

namespace App\Core\Platform\SupportAccess\Http\Requests;

use App\Core\Platform\SupportAccess\Models\SupportAccessGrant;

/** RBAC-13a: ask a tenant for access: a reason, 1 to 72 hours, read or write. */
class AskSupportAccessRequest extends PlatformSupportRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'mode' => ['required', 'string', 'in:'.implode(',', SupportAccessGrant::MODES)],
            'hours' => ['required', 'integer', 'min:1', 'max:'.SupportAccessGrant::MAX_HOURS],
        ];
    }
}
