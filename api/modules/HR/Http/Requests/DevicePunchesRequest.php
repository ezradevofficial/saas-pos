<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST hr/device/punches (HR-24): a biometric device's punches, up to 500:
 * its own event id, the employee's enrolment reference, `in`, `out` or
 * `auto`, and the instant (ISO 8601 with offset).
 */
class DevicePunchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'punches' => ['required', 'array', 'min:1', 'max:500'],
            'punches.*.external_id' => ['required', 'string', 'max:100', 'distinct'],
            'punches.*.ref' => ['required', 'string', 'max:64'],
            'punches.*.kind' => ['required', Rule::in(['in', 'out', 'auto'])],
            'punches.*.occurred_at' => ['required', 'date', HrRequest::INSTANT],
        ];
    }
}
