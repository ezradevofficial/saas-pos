<?php

namespace Modules\HR\Http\Requests;

/**
 * GET hr/me (HR-31): any signed-in user may ask; a user not linked to an
 * active employee is answered 204 No Content, not an error (decision 30).
 */
class ShowSelfRequest extends SelfRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
