<?php

namespace App\Core\Rbac\Policies;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;

/**
 * RBAC-02: roles are tenant-wide, so managing them takes the permission at
 * tenant scope. Viewing the list (read-only) is allowed from any scope.
 */
class RolePolicy
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function viewAny(User $actor): bool
    {
        return $this->resolver->can($actor, 'core.role.view');
    }

    public function view(User $actor, Role $role): bool
    {
        return $role->tenant_id === $actor->tenant_id && $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->resolver->can($actor, 'core.role.create', Scope::tenant());
    }

    public function update(User $actor, Role $role): bool
    {
        return $this->view($actor, $role) && $this->resolver->can($actor, 'core.role.edit', Scope::tenant());
    }

    public function copy(User $actor, Role $role): bool
    {
        return $this->view($actor, $role) && $this->create($actor);
    }

    public function archive(User $actor, Role $role): bool
    {
        return $this->view($actor, $role) && $this->resolver->can($actor, 'core.role.archive', Scope::tenant());
    }
}
