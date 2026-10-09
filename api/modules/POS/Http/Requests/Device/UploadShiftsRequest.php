<?php

namespace Modules\POS\Http\Requests\Device;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST pos/shifts: shifts opened, and closed with the cash counted (POS-04).
 * A currency appears once per float or count of one shift; a batch holds
 * several shifts in the same currencies (the close of one shift and the
 * open of the next, NFR-04).
 */
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
            'shifts.*.opening_float' => ['present', 'array', 'max:10', $this->currenciesOnce()],
            'shifts.*.opening_float.*.currency' => $this->currencyCode(),
            'shifts.*.opening_float.*.amount_minor' => $this->minor(),
            'shifts.*.closing' => ['nullable', 'array'],
            'shifts.*.closing.closed_by_id' => ['required_with:shifts.*.closing', 'uuid'],
            'shifts.*.closing.closed_at' => ['required_with:shifts.*.closing', 'date'],
            'shifts.*.closing.counted' => ['sometimes', 'array', 'max:10', $this->currenciesOnce()],
            'shifts.*.closing.counted.*.currency' => $this->currencyCode(),
            'shifts.*.closing.counted.*.amount_minor' => $this->minor(),
            'shifts.*.closing.note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Each currency once within this list. Laravel's `distinct` on a nested
     * wildcard compares across every shift of the batch, which refused a
     * batch closing one shift and opening the next in the same currency.
     */
    private function currenciesOnce(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_array($value)) {
                return;
            }

            $codes = array_filter(array_map(fn ($row) => is_array($row) && is_string($row['currency'] ?? null) ? $row['currency'] : null, $value));

            if (count($codes) !== count(array_unique($codes))) {
                $fail(__('validation.distinct'));
            }
        };
    }
}
