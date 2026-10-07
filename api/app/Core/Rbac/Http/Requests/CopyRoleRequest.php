<?php

namespace App\Core\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST roles/{role}/copy {name}: `core.role.create` at tenant scope (RBAC-02). */
class CopyRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('copy', $this->route('role'));
    }

    public function rules(): array
    {
        return ['name' => ['required', ...RoleRules::name()]];
    }
}
