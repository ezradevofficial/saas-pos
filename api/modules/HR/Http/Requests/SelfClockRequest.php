<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** POST hr/me/clock (HR-31, HR-22): a web punch now; `kind` defaults to the opposite of the last one today. */
class SelfClockRequest extends SelfRequest
{
    public function rules(): array
    {
        return ['kind' => ['sometimes', Rule::in(['in', 'out'])]];
    }
}
