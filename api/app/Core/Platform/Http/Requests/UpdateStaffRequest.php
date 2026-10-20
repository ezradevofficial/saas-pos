<?php

namespace App\Core\Platform\Http\Requests;

use App\Core\Platform\Models\PlatformUser;
use Illuminate\Validation\Rule;

/** ADM-07: change a staff member's role (platform owners only). */
class UpdateStaffRequest extends ManageStaffRequest
{
    public function rules(): array
    {
        return ['role' => ['required', 'string', Rule::in(PlatformUser::ROLES)]];
    }
}
