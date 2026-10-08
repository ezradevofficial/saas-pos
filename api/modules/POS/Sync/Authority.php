<?php

namespace Modules\POS\Sync;

use App\Core\Identity\Models\User;
use App\Core\Rbac\LimitRules;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Device;
use Brick\Math\BigDecimal;
use Closure;

/**
 * Who may do what at the till (RBAC-04, RBAC-06, AUTH-07, AUTH-08). Users
 * named in an upload are read under the device's tenant (another tenant's
 * user does not exist). A restricted action is allowed when the person who
 * did it holds the permission at the device's location within their
 * limit, or when a manager's override holds both; the override proof goes
 * through the OverrideVerifier.
 *
 * Limits (RBAC-06): the highest value across the roles at scopes covering
 * the location; no rule means not allowed; an Owner role has no limits.
 */
class Authority
{
    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly LimitRules $limits,
        private readonly OverrideVerifier $verifier,
    ) {}

    public function user(?string $id, string $field): User
    {
        $user = $id === null ? null : User::query()->find($id);

        return $user ?? throw new Rejection('user_unknown', $field);
    }

    public function can(User $user, string $permission, Scope $scope): bool
    {
        return $user->isActive() && $this->resolver->can($user, $permission, $scope);
    }

    /** RBAC-06: $value is within $user's $key limit at $scope. */
    public function within(User $user, string $key, Scope $scope, BigDecimal $value): bool
    {
        if ($this->isOwner($user, $scope)) {
            return true;
        }

        $max = $this->limits->max($user, $key, $scope);

        return $max !== null && $value->isLessThanOrEqualTo($max);
    }

    /**
     * Allow $permission (and $withinLimit, when given) for $actor, else for
     * the manager named in $override; refuse otherwise.
     *
     * @param  array{manager_id?: ?string, proof?: ?string}|null  $override
     * @param  (Closure(User): bool)|null  $withinLimit
     */
    public function approve(User $actor, ?array $override, string $permission, Scope $scope, ?Closure $withinLimit, Device $device, string $subjectId, string $field): Approval
    {
        $allowed = fn (User $user) => $this->can($user, $permission, $scope) && ($withinLimit === null || $withinLimit($user));
        // A named manager must exist in the tenant, needed or not: an unknown id is a bad reference.
        $manager = ($override['manager_id'] ?? null) === null ? null : $this->user($override['manager_id'], "{$field}.manager_id");

        // The person may do it themselves: an override sent along is not needed.
        if ($allowed($actor)) {
            return new Approval;
        }

        if ($manager === null) {
            throw new Rejection($this->can($actor, $permission, $scope) ? 'limit_exceeded' : 'override_required', $field);
        }

        if (! $allowed($manager)) {
            throw new Rejection($this->can($manager, $permission, $scope) ? 'override_limit_exceeded' : 'override_not_permitted', "{$field}.manager_id");
        }

        return new Approval($manager, $this->verifier->verify($manager, $override['proof'] ?? null, $permission, $subjectId, $device));
    }

    private function isOwner(User $user, Scope $scope): bool
    {
        $roles = $this->resolver->roleIds($user, $scope);

        return $roles !== [] && Role::query()->whereKey($roles)->where('is_owner', true)->exists();
    }
}
