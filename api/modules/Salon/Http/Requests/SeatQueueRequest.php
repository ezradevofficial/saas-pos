<?php

namespace Modules\Salon\Http\Requests;

use Illuminate\Validation\Rule;

/** POST salon/queue/{id}/seat: `salon.queue.manage` at the entry's branch; the booking then needs `salon.appointment.create` there. */
class SeatQueueRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'uuid', Rule::exists('salon_staff', 'id')],
            'resource_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('salon_resources', 'id')],
        ];
    }
}
