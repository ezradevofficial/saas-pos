<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST hr/till/punches (HR-23, NFR-04): a paired POS device's queued
 * clock-ins, up to 500, each with the device's own UUID; employees are
 * checked under RLS, then against the device's place.
 */
class TillPunchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'punches' => ['required', 'array', 'min:1', 'max:500'],
            'punches.*.id' => ['required', 'uuid', 'distinct'],
            'punches.*.employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')],
            'punches.*.kind' => ['required', Rule::in(['in', 'out'])],
            'punches.*.occurred_at' => ['required', 'date'],
        ];
    }
}
