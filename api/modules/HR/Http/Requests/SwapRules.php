<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** HR-19: a swap's body. */
final class SwapRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'roster_shift_id' => ['required', 'uuid', Rule::exists('hr_roster_shifts', 'id')],
            'peer_employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')],
            'peer_roster_shift_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('hr_roster_shifts', 'id')],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
