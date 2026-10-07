<?php

namespace App\Core\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResendCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['challenge_id' => ['required', 'string', 'max:64']];
    }
}
