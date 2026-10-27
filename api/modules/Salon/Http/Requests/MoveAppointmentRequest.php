<?php

namespace Modules\Salon\Http\Requests;

use Illuminate\Validation\Rule;

/** POST salon/appointments/{id}/move (SAL-09): `salon.appointment.edit` at the appointment. */
class MoveAppointmentRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'lines' => ['sometimes', 'array', 'max:12'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.staff_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('salon_staff', 'id')],
            'lines.*.resource_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('salon_resources', 'id')],
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
