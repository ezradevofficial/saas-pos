<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST fuel/device/meter-resets (FUEL-17, FUEL-22): a paired till's device
 * token; the place is the device's. At most 10 requests; a reset is only
 * ever requested here, approving it is a person's act in the back office.
 */
class DeviceMeterResetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resets' => ['required', 'array', 'min:1', 'max:10'],
            'resets.*.id' => ['required', 'uuid'],
            'resets.*.nozzle_id' => ['required', 'uuid'],
            'resets.*.shift_id' => ['nullable', 'uuid'],
            'resets.*.reading_before' => FuelRules::litres(),
            'resets.*.reading_after' => FuelRules::litres(),
            'resets.*.reason' => FuelRules::reason(),
            'resets.*.requested_by_id' => ['required', 'uuid'],
            'resets.*.requested_at' => ['required', 'date'],
            'resets.*.actor_proof' => ['nullable', 'array'],
        ];
    }
}
