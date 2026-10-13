<?php

namespace App\Core\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The second step: the challenge from the password step and a TOTP code. */
class PlatformChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string', 'max:2000'],
            'code' => ['required', 'string', 'max:16'],
        ];
    }
}
