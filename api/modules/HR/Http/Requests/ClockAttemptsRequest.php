<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST hr/till/clock-attempts (HR-23): a paired POS device reports the
 * wrong clock-in PINs it counted, and lockouts: `{reports: [{employee_id,
 * failed_attempts, locked, at?}]}`, up to 100. Employees are checked under
 * RLS.
 */
class ClockAttemptsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reports' => ['required', 'array', 'min:1', 'max:100'],
            'reports.*.employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')],
            'reports.*.failed_attempts' => ['required', 'integer', 'between:1,100'],
            'reports.*.locked' => ['required', 'boolean'],
            'reports.*.at' => ['sometimes', 'nullable', 'date', HrRequest::INSTANT],
        ];
    }
}
