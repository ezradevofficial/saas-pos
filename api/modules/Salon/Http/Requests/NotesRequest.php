<?php

namespace Modules\Salon\Http\Requests;

/** PUT salon/appointments/{id}/notes: the formula or notes of the visit; `salon.client.notes` at the appointment. */
class NotesRequest extends ServeRequest
{
    public function rules(): array
    {
        return [
            'service_notes' => ['present', 'nullable', 'string', 'max:2000'],
        ];
    }
}
