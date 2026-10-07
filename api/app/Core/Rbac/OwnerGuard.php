<?php

namespace App\Core\Rbac;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Owner safety (RBAC-10, review focus 5): a tenant always keeps at least
 * one active Owner, that is an active user holding an unarchived owner role
 * at tenant scope. Checked before removing an assignment, deactivating a
 * user or archiving a role.
 *
 * Every check first takes a per-tenant transaction lock on the owner set,
 * so two removals running at once are serialised: the second counts the
 * owners only after the first has committed (or rolled back). A check must
 * therefore run inside the transaction that makes the change; protect()
 * does both.
 */
class OwnerGuard
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @return list<string> ids of the current tenant's active Owners */
    public function ownerIds(?Closure $except = null): array
    {
        return RoleAssignment::query()
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('users', 'users.id', '=', 'role_assignments.user_id')
            ->where('roles.is_owner', true)
            ->whereNull('roles.archived_at')
            ->where('role_assignments.scope_type', Scope::TENANT)
            ->where('users.status', User::STATUS_ACTIVE)
            ->when($except, fn (Builder $query) => $except($query))
            ->distinct()
            ->pluck('role_assignments.user_id')
            ->all();
    }

    public function isOwner(User $user): bool
    {
        return in_array($user->getKey(), $this->ownerIds(), true);
    }

    /**
     * Run $change in a transaction, after checking under the owners lock
     * that $target may stop being an Owner.
     *
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     */
    public function protect(User $target, Closure $change): mixed
    {
        return $this->connection()->transaction(function () use ($target, $change) {
            $this->assertNotLastOwner($target);

            return $change();
        });
    }

    /** Refuse when $target stopping being an Owner leaves none: 422 `last_owner`. */
    public function assertNotLastOwner(User $target): void
    {
        $this->assertOwnersRemain(fn (Builder $q) => $q->where('role_assignments.user_id', '!=', $target->getKey()));
    }

    /** Refuse when removing $assignment leaves no Owner. */
    public function assertAssignmentRemovable(RoleAssignment $assignment): void
    {
        $this->assertOwnersRemain(fn (Builder $q) => $q->where('role_assignments.id', '!=', $assignment->getKey()));
    }

    /** Refuse when archiving $role leaves no Owner. */
    public function assertRoleArchivable(Role $role): void
    {
        $this->assertOwnersRemain(fn (Builder $q) => $q->where('roles.id', '!=', $role->getKey()));
    }

    /**
     * Lock the owner set, then refuse a change that takes the tenant from
     * some Owners to none. Inside a transaction only: the lock is held
     * until it ends.
     */
    private function assertOwnersRemain(Closure $except): void
    {
        $this->lock();

        if ($this->ownerIds() !== [] && $this->ownerIds($except) === []) {
            throw new ApiException(422, 'last_owner', __('rbac.errors.last_owner'));
        }
    }

    private function lock(): void
    {
        $db = $this->connection();

        if ($db->transactionLevel() === 0) {
            throw new LogicException('OwnerGuard checks must run inside the transaction making the change.');
        }

        $db->select("select pg_advisory_xact_lock(hashtext('owners:' || ?))", [$this->tenants->require()]);
    }

    private function connection(): Connection
    {
        return DB::connection(TenantContext::CONNECTION);
    }
}
