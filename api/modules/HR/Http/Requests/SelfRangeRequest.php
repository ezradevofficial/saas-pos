<?php

namespace Modules\HR\Http\Requests;

/** GET hr/me/roster, hr/me/attendance (HR-31): `?from=`, `?to=` (at most 62 days; this week by default). */
class SelfRangeRequest extends SelfRequest
{
    public function rules(): array
    {
        return ['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']];
    }
}
