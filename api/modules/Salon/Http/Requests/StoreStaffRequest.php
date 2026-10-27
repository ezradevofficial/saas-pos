<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST salon/staff (SAL-03): `salon.staff.manage` at the branch (or company). */
class StoreStaffRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],

            'name' => ['required', 'string', 'max:120'],
            'title' => ['sometimes', 'nullable', 'string', 'max:80'],
            'color' => ['sometimes', 'required', 'string', 'in:c1,c2,c3,c4,c5,c6,c7,c8'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('branches', 'id')],
            'user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')],
            'employee_id' => ['sometimes', 'nullable', 'uuid'],
            'accepts_online' => ['sometimes', 'required', 'boolean'],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->atBranch($this->input('branch_id')) ?? $this->atCompany($this->input('company_id'));
    }
}
