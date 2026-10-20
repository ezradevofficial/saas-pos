<?php

namespace App\Core\Platform\Http\Requests;

use App\Core\Platform\Models\PlatformUser;
use Illuminate\Validation\Rule;

/** ADM-07: invite a staff member with a role (platform owners only). */
class InviteStaffRequest extends ManageStaffRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', 'string', Rule::in(PlatformUser::ROLES)],
        ];
    }
}
