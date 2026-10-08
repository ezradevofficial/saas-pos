<?php

namespace App\Core\Identity\Pin\Http\Requests;

use App\Core\Sync\Http\Requests\DeviceRequest;
use Illuminate\Validation\Rule;

/**
 * AUTH-06: POST pos/pin/attempts {reports: [{user_id, failed_attempts,
 * locked, occurred_at?}]}, wrong PINs the device counted offline.
 */
class ReportPinAttemptsRequest extends DeviceRequest
{
    public function rules(): array
    {
        return [
            'reports' => ['required', 'array', 'min:1', 'max:100'],
            'reports.*.user_id' => ['required', 'uuid', 'distinct', Rule::exists('users', 'id')],
            'reports.*.failed_attempts' => ['required', 'integer', 'min:0', 'max:1000'],
            'reports.*.locked' => ['required', 'boolean'],
            'reports.*.occurred_at' => ['nullable', 'date'],
        ];
    }
}
