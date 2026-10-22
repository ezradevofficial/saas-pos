<?php

namespace App\Core\Rbac\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\Rbac\Grants;
use App\Core\Rbac\Http\Lists\RoleList;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * RBAC-02: GET roles (`core.role.view`): `?status` (active by default),
 * `?search=` (name or description), `?per_page`, `?sort` (system roles
 * first, then by name, by default) and an export (`?format`,
 * `?columns[]`; RoleList, EXP-01). `?grantable_at=` (`any`, `tenant` or
 * `company|branch|location:<id>`) keeps the roles the user may grant there,
 * by the API's own rules (Grants; `?grantable_for=invite` also needs
 * `core.user.invite`), so pickers never offer a refusal (security review 5).
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
            'grantable_at' => ['sometimes', 'string', 'regex:/^(any|tenant|(company|branch|location):[0-9a-fA-F-]{36})$/'],
            'grantable_for' => ['sometimes', 'string', 'in:assign,invite'],
        ];
    }

    /** The place `grantable_at` names: null for `any`, false when absent. */
    public function grantableAt(Grants $grants): Scope|false|null
    {
        $value = $this->validated('grantable_at');

        if ($value === null) {
            return false;
        }

        if ($value === 'any') {
            return null;
        }

        [$type, $id] = array_pad(explode(':', $value, 2), 2, null);

        // Checked to exist in the tenant (404 otherwise), as a grant would be.
        return $grants->scope($type, $id);
    }
}
