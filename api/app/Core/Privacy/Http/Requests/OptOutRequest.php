<?php

namespace App\Core\Privacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET and POST /api/v1/opt-out/{token} (NFR-08). The token in the path is
 * the credential (checked by OptOuts). A one-click unsubscribe from a mail
 * client posts `List-Unsubscribe=One-Click` (RFC 8058); nothing else is read.
 */
class OptOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['List-Unsubscribe' => ['sometimes', 'nullable', 'string', 'max:20']];
    }
}
