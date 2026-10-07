<?php

namespace App\Core\Identity\Http\Requests;

class VerifyRequest extends SignInRequest
{
    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:16'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
