<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST salon/resources (SAL-05): `salon.resource.manage` at the branch. */
class StoreResourceRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['sometimes', 'required', 'string', 'max:30'],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->atBranch($this->input('branch_id'));
    }
}
