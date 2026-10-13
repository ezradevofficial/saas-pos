<?php

namespace App\Core\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Reading the setup link before using it (who it is for). */
class ShowPlatformSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
