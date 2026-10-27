<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Tenancy\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST hotel/device/room-lookup (HOT-22, HOT-35): a paired till (device token) asks, online, whether a room holds an in-house guest who can be
 * charged: `{room, amount_minor?, currency?}`. The answer is for the till's own branch only; the guest's name is all it learns.
 */
class DeviceRoomLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return [
            'room' => ['required', 'string', 'max:20'],
            'amount_minor' => ['sometimes', 'integer', 'min:1', 'max:999999999999999'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
        ];
    }
}
