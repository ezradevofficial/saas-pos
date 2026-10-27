<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST salon/queue (SAL-12): `salon.queue.manage` at the branch. */
class StoreQueueRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'client_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
            'guest_name' => ['required_without:client_id', 'nullable', 'string', 'max:120'],
            'guest_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'service_ids' => ['required', 'array', 'min:1', 'max:12'],
            'service_ids.*' => ['uuid', Rule::exists('salon_services', 'id')],
            'staff_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('salon_staff', 'id')],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->atBranch($this->input('branch_id'));
    }
}
