<?php

namespace Modules\Salon\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The public booking page's requests: no token, rate-limited by the route; the branch is found by the code (ResolveBooking). */
class PublicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
