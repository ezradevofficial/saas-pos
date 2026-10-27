<?php

namespace Modules\Salon\Http\Requests;

/** POST salon-booking/{code}/appointments (SAL-15, SAL-26): a name, a phone or an email, the services and a time. Nothing else about the visitor is asked. */
class PublicBookRequest extends PublicRequest
{
    public function rules(): array
    {
        return [
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+() \-]{7,30}$/'],
            'guest_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'service_ids' => ['required', 'array', 'min:1', 'max:6'],
            'service_ids.*' => ['uuid'],
            'staff_id' => ['sometimes', 'nullable', 'uuid'],
            'starts_at' => ['required', 'date'],
            'locale' => ['sometimes', 'nullable', 'string', 'in:en,fr'],
        ];
    }
}
