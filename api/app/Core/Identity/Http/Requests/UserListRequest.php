<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * GET users: `?status=active|pending|deactivated|all` (default all) and
 * `?per_page`. Needs `core.user.view` somewhere; the list is filtered to the
 * user's scope by the controller (RBAC-04).
 */
class UserListRequest extends ListRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', User::class);
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:active,pending,deactivated,all'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
        ];
    }

    public function applyStatus(Builder $query): Builder
    {
        $status = $this->validated('status', 'all');

        return $status === 'all' ? $query : $query->where('status', $status);
    }
}
