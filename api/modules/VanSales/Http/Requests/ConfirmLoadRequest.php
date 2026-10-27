<?php

namespace Modules\VanSales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST van-sales/device/loads/{id}/confirm (VAN-08): a van device's token; the person must hold `van_sales.load.confirm` at its location (checked by the controller). */
class ConfirmLoadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid'],
            'actor_proof' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['required', 'uuid'],
            'lines.*.qty_received' => ['required', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ];
    }
}
