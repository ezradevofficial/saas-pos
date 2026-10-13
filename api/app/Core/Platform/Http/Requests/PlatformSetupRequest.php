<?php

namespace App\Core\Platform\Http\Requests;

use App\Core\Identity\Rules\MaxBytes;
use App\Core\Identity\Rules\NotCommonPassword;
use Illuminate\Foundation\Http\FormRequest;

/** The one-time setup link: a password of at least 12 characters, typed twice. */
class PlatformSetupRequest extends FormRequest
{
    public const MIN_LENGTH = 12;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:'.self::MIN_LENGTH, new NotCommonPassword, new MaxBytes(72), 'confirmed'],
        ];
    }
}
