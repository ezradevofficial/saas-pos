<?php

namespace Modules\Salon\Http\Requests;

use Illuminate\Validation\Rule;

/** PUT salon/staff/{id}: `salon.staff.manage` at the staff member's place. */
class UpdateStaffRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'title' => ['sometimes', 'nullable', 'string', 'max:80'],
            'color' => ['sometimes', 'required', 'string', 'in:c1,c2,c3,c4,c5,c6,c7,c8'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('branches', 'id')],
            'user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')],
            'employee_id' => ['sometimes', 'nullable', 'uuid'],
            'accepts_online' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
