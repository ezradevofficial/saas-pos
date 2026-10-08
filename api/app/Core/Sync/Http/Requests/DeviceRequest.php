<?php

namespace App\Core\Sync\Http\Requests;

use App\Core\Tenancy\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request from a paired device (TEN-05). EnsureDeviceToken has already
 * refused anything but a device token; the device acts at its location.
 */
abstract class DeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function device(): Device
    {
        /** @var Device */
        return $this->user();
    }

    public function rules(): array
    {
        return [];
    }
}
