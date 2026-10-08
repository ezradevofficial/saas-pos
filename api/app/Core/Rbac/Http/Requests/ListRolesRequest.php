<?php

namespace App\Core\Rbac\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\Rbac\Http\Lists\RoleList;
use App\Core\Rbac\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RBAC-02: GET roles (`core.role.view`): `?status` (active by default),
 * `?search=` (name or description), `?per_page`, `?sort` (system roles
 * first, then by name, by default) and an export (`?format`,
 * `?columns[]`; RoleList, EXP-01).
 */
class ListRolesRequest extends FormRequest
{
    use ListsRecords;

    public function list(): ListDefinition
    {
        return new RoleList;
    }

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Role::class);
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
