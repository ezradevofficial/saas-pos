<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Visibility;
use Illuminate\Foundation\Http\FormRequest;

/** TEN-05: a device is created at its location's scope, then paired. */
class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Location $location */
        $location = $this->route('location');

        abort_unless(app(Visibility::class)->reaches($this->user(), 'core.device.view', $location), 404);

        return $this->user()->can('create', [Device::class, $location]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // NUM-01: printed in document numbers ({LOCATION}, {DEVICE}).
            'code' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{1,10}$/'],
        ];
    }
}
