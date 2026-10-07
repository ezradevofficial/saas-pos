<?php

namespace App\Core\Rbac\Http\Requests;

use App\Core\Rbac\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

/** POST roles: `core.role.create` at tenant scope (RBAC-02). */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Role::class);
    }

    public function rules(): array
    {
        return RoleRules::rules();
    }
}
