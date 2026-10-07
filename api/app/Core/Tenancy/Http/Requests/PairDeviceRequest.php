<?php

namespace App\Core\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** TEN-05: public; the code itself is the credential. */
class PairDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }
}
