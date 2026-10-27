<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\AttendanceDevice;

/** GET hr/connector/devices (HR-24, MKT-04): attendance devices, by `status` (active by default, or all). */
class ConnectorDevicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at each device's location (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([AttendanceDevice::ACTIVE, AttendanceDevice::SUSPENDED, AttendanceDevice::RETIRED, 'all'])],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
