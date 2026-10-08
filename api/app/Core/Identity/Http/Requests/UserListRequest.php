<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Identity\Http\Lists\UserList;
use App\Core\Identity\Models\User;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * GET users: `?status=active|pending|deactivated|all` (default all),
 * `?search=` (name, email or phone), `?per_page`, `?sort` and an export
 * (`?format`, `?columns[]`; UserList, EXP-01). Needs `core.user.view`
 * somewhere; the list is filtered to the user's scope by the controller
 * (RBAC-04).
 */
class UserListRequest extends ListRequest
{
    use SortsAndExports;

    private ?UserList $definition = null;

    public function list(): ListDefinition
    {
        return $this->definition ??= new UserList($this->user());
    }

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', User::class);
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', 'in:active,pending,deactivated,all'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }

    public function applyStatus(Builder $query): Builder
    {
        $status = $this->validated('status', 'all');

        return $status === 'all' ? $query : $query->where('status', $status);
    }
}
