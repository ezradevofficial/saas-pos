<?php

namespace Modules\Salon\Http\Requests;

/** GET|POST salon-booking/{code}/appointments/{number}/...: the booking is named by its number and the phone it was made with. */
class PublicOwnedRequest extends PublicRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:30'],
        ];
    }
}
