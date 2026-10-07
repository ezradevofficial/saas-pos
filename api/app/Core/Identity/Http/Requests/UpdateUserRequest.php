<?php

namespace App\Core\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH users/{user}: name and language only (contacts change through
 * verification). A user out of scope is not found (RBAC-04).
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');
        abort_unless($this->user()->can('view', $target), 404);

        return $this->user()->can('update', $target);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'locale' => ['sometimes', 'required', 'string', Rule::in(['en', 'fr'])],
        ];
    }
}
