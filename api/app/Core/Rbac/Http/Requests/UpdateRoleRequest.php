<?php

namespace App\Core\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH roles/{role}: `core.role.edit` at tenant scope; system roles are refused by RoleManager. */
class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('role'));
    }

    public function rules(): array
    {
        $rules = RoleRules::rules($this->route('role')->id);
        $rules['name'] = ['sometimes', 'required', ...RoleRules::name($this->route('role')->id)];

        return $rules;
    }
}
