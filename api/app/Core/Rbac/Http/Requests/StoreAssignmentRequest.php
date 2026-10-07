<?php

namespace App\Core\Rbac\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST users/{user}/assignments: the user must be visible (404); whether
 * the role may be granted there is checked by Grants (RBAC-04).
 */
class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->user()->can('view', $this->route('user')), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'role_id' => ['required', 'uuid'],
            'scope_type' => ['required', 'string', Rule::in(Scope::TYPES)],
            'scope_id' => ['nullable', 'required_unless:scope_type,'.Scope::TENANT, 'uuid'],
        ];
    }
}
