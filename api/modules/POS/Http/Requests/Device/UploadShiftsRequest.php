<?php

namespace Modules\POS\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/** POST pos/shifts: shifts opened, and closed with the cash counted (POS-04). */
class UploadShiftsRequest extends FormRequest
{
    use UploadRules;

    public function rules(): array
    {
        return [
            'shifts' => ['required', 'array', 'min:1', 'max:20'],
            'shifts.*.id' => $this->deviceId(),
            'shifts.*.opened_by_id' => ['required', 'uuid'],
            'shifts.*.opened_at' => ['required', 'date'],
            'shifts.*.opening_float' => ['present', 'array', 'max:10'],
            'shifts.*.opening_float.*.currency' => [...$this->currencyCode(), 'distinct'],
            'shifts.*.opening_float.*.amount_minor' => $this->minor(),
            'shifts.*.closing' => ['nullable', 'array'],
            'shifts.*.closing.closed_by_id' => ['required_with:shifts.*.closing', 'uuid'],
            'shifts.*.closing.closed_at' => ['required_with:shifts.*.closing', 'date'],
            'shifts.*.closing.counted' => ['sometimes', 'array', 'max:10'],
            'shifts.*.closing.counted.*.currency' => [...$this->currencyCode(), 'distinct'],
            'shifts.*.closing.counted.*.amount_minor' => $this->minor(),
            'shifts.*.closing.note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
