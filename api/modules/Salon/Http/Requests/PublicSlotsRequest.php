<?php

namespace Modules\Salon\Http\Requests;

/** GET salon-booking/{code}/slots: services, a date and optionally a staff member. */
class PublicSlotsRequest extends PublicRequest
{
    public function rules(): array
    {
        return [
            'service_ids' => ['required', 'array', 'min:1', 'max:6'],
            'service_ids.*' => ['uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'staff_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
