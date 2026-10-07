<?php

namespace App\Core\Identity\Policies;

use App\Core\Identity\Models\User;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\ScopeNames;
use App\Core\Rbac\ScopeResolver;

/**
 * RBAC-04 for users, who have no scope of their own.
 *
 * - Seeing a user: the actor holds the permission at a scope covering at
 *   least one of the user's assignments.
 * - Changing a user (edit, deactivate, reactivate, sign out everywhere):
 *   the actor covers every one of the user's assignments, so a branch
 *   manager cannot act on someone who also holds a role above the branch.
 *
 * Tenant-wide holders cover everything; users without assignments are
 * reached only from tenant scope. Managing someone holding the Owner role
 * (active or not) takes an Owner (RBAC-10).
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
        return $this->covers($actor, 'core.user.view', $target, all: false);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->covers($actor, 'core.user.edit', $target, all: true) && $this->mayManageOwner($actor, $target);
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $this->covers($actor, 'core.user.deactivate', $target, all: true) && $this->mayManageOwner($actor, $target);
    }

    public function reactivate(User $actor, User $target): bool
    {
        return $this->deactivate($actor, $target);
    }

    public function signOutEverywhere(User $actor, User $target): bool
    {
        return $this->covers($actor, 'core.user.edit', $target, all: true) && $this->mayManageOwner($actor, $target);
    }

    /**
     * Whether $actor holds $permission over $target's assignments: at least
     * one of them, or every one of them when $all.
     */
    private function covers(User $actor, string $permission, User $target, bool $all): bool
    {
        if ($target->tenant_id !== $actor->tenant_id) {
            return false;
        }

        $visible = $this->resolver->visibleIds($actor, $permission);

        if ($visible->all) {
            return true;
        }

        $assignments = $target->assignments()->get(['scope_type', 'scope_id']);
        $covered = fn ($a) => ScopeNames::covers($visible, $a->scope_type, $a->scope_id);

        if ($assignments->isEmpty()) {
            return false;
        }

        return $all ? $assignments->every($covered) : $assignments->contains($covered);
    }

    private function mayManageOwner(User $actor, User $target): bool
    {
        return ! $this->owners->holdsOwnerRole($target) || $this->owners->isOwner($actor);
    }
}
