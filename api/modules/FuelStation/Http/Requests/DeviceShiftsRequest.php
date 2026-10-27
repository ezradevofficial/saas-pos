<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FuelStation\Shifts\Tenders;

/**
 * POST fuel/device/shifts (FUEL-22): a paired till's device token (the
 * route's middleware); the place is the device's, never the body's. At most
 * 10 shifts; every id is read under the device's tenant and station. Amounts
 * are minor-unit digits, readings and litres decimals with up to 3 places,
 * dips in cm with up to 2.
 */
class DeviceShiftsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $dips = function (string $prefix): array {
            return [
                "{$prefix}.dips" => ['nullable', 'array', 'max:20'],
                "{$prefix}.dips.*.id" => ['required', 'uuid'],
                "{$prefix}.dips.*.tank_id" => ['required', 'uuid'],
                "{$prefix}.dips.*.dip_cm" => FuelRules::cm(),
                "{$prefix}.dips.*.water_cm" => FuelRules::cm(false, true),
                "{$prefix}.dips.*.taken_at" => ['required', 'date'],
            ];
        };

        return [
            'shifts' => ['required', 'array', 'min:1', 'max:10'],
            'shifts.*.id' => ['required', 'uuid'],
            'shifts.*.opening' => ['required', 'array'],
            'shifts.*.opening.opened_by_id' => ['required', 'uuid'],
            'shifts.*.opening.opened_at' => ['required', 'date'],
            'shifts.*.opening.actor_proof' => ['nullable', 'array'],
            'shifts.*.opening.nozzles' => ['required', 'array', 'min:1', 'max:40'],
            'shifts.*.opening.nozzles.*.nozzle_id' => ['required', 'uuid'],
            'shifts.*.opening.nozzles.*.attendant_id' => ['required', 'uuid'],
            'shifts.*.opening.nozzles.*.attendant_proof' => ['nullable', 'array'],
            'shifts.*.opening.nozzles.*.reading' => FuelRules::litres(),
            ...$dips('shifts.*.opening'),
            'shifts.*.closing' => ['nullable', 'array'],
            'shifts.*.closing.closed_by_id' => ['required_with:shifts.*.closing', 'uuid'],
            'shifts.*.closing.closed_at' => ['required_with:shifts.*.closing', 'date'],
            'shifts.*.closing.actor_proof' => ['nullable', 'array'],
            'shifts.*.closing.note' => ['nullable', 'string', 'max:500'],
            'shifts.*.closing.nozzles' => ['required_with:shifts.*.closing', 'array', 'max:40'],
            'shifts.*.closing.nozzles.*.nozzle_id' => ['required', 'uuid'],
            'shifts.*.closing.nozzles.*.reading' => FuelRules::litres(),
            'shifts.*.closing.nozzles.*.test_litres' => FuelRules::litres(false, true),
            'shifts.*.closing.tenders' => ['required_with:shifts.*.closing', 'array', 'max:40'],
            'shifts.*.closing.tenders.*.attendant_id' => ['required', 'uuid'],
            'shifts.*.closing.tenders.*.tenders' => ['required', 'array', 'min:1', 'max:5'],
            'shifts.*.closing.tenders.*.tenders.*.kind' => ['required', 'string', Rule::in(Tenders::KINDS)],
            'shifts.*.closing.tenders.*.tenders.*.amount_minor' => ['required', 'regex:/^\d{1,15}$/'],
            ...$dips('shifts.*.closing'),
        ];
    }
}
