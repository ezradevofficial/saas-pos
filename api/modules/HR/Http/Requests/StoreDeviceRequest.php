<?php

namespace Modules\HR\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST hr/attendance-devices (HR-24): `hr.device.manage` at the location. */
class StoreDeviceRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), 'hr.device.manage');
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')->whereNull('archived_at')],
            'name' => ['required', 'string', 'max:120'],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && ! $this->access()->at($this->user(), 'hr.device.manage', Scope::location((string) $this->input('location_id')))) {
                $this->forbid();
            }
        }];
    }
}
