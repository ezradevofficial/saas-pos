<?php

namespace App\Core\Identity\Policies;

use App\Core\Identity\Models\User;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\ScopeNames;
use App\Core\Rbac\ScopeResolver;

/**
 * RBAC-04 for users, who have no scope of their own: an actor reaches a
 * user when they hold the permission at a scope covering at least one of
 * the user's assignments. Users without assignments are reached only from
 * tenant scope. Managing an Owner takes an Owner (RBAC-10).
 */
class UserPolicy
{
    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly OwnerGuard $owners,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->resolver->can($actor, 'core.user.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $this->reaches($actor, 'core.user.view', $target);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->reaches($actor, 'core.user.edit', $target);
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $this->reaches($actor, 'core.user.deactivate', $target) && $this->mayManageOwner($actor, $target);
    }

    public function reactivate(User $actor, User $target): bool
    {
        return $this->deactivate($actor, $target);
    }

    public function signOutEverywhere(User $actor, User $target): bool
    {
        return $this->reaches($actor, 'core.user.edit', $target) && $this->mayManageOwner($actor, $target);
    }

    private function reaches(User $actor, string $permission, User $target): bool
    {
        if ($target->tenant_id !== $actor->tenant_id) {
            return false;
        }

        $visible = $this->resolver->visibleIds($actor, $permission);

        if ($visible->all) {
            return true;
        }

        return $target->assignments()->get(['scope_type', 'scope_id'])
            ->contains(fn ($a) => ScopeNames::covers($visible, $a->scope_type, $a->scope_id));
    }

    private function mayManageOwner(User $actor, User $target): bool
    {
        return ! $this->owners->isOwner($target) || $this->owners->isOwner($actor);
    }
}
