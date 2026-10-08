<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Tenancy\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Device $device */
        $device = $this->route('device');

        abort_unless($this->user()->can('view', $device), 404);

        return $this->user()->can('update', $device);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            // NUM-01: printed in document numbers ({LOCATION}, {DEVICE}).
            'code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{1,10}$/'],
        ];
    }
}
