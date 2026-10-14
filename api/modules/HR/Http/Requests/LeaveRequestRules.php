<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** HR-14: a leave request's body. */
final class LeaveRequestRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'uuid', Rule::exists('hr_leave_types', 'id')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_half' => ['sometimes', 'boolean'],
            'end_half' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
