<?php

namespace App\Core\CustomForms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CF-04: GET custom-form-types: the types the user may use (the Forms
 * menu), or every type, archived ones too with `?status=all|archived`, for
 * those who manage them. Every signed-in user may ask; the list is theirs.
 */
class ListCustomFormTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:active,archived,all'],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
