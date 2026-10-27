<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST salon/appointments (SAL-07): `salon.appointment.create` at the branch; booking outside hours needs `salon.appointment.override` and a reason. */
class StoreAppointmentRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'salon_client_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
            'guest_name' => ['required_without:salon_client_id', 'nullable', 'string', 'max:120'],
            'guest_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'guest_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'starts_at' => ['required', 'date'],
            'source' => ['sometimes', 'string', 'in:staff,walk_in'],
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:200'],

            'lines' => ['required', 'array', 'min:1', 'max:12'],
            'lines.*.service_id' => ['required', 'uuid', Rule::exists('salon_services', 'id')],
            'lines.*.staff_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('salon_staff', 'id')],
            'lines.*.resource_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('salon_resources', 'id')],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->atBranch($this->input('branch_id'));
    }
}
