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
     * Allow $permission (and $withinLimit, when given) by a manager's
     * override, else by the person who did it; refuse otherwise.
     *
     * - An override (core shape, AUTH-08) is redeemed once for $reference.
     *   A named manager must exist in the tenant, needed or not, and must
     *   hold the permission within the limit; else the person's own right
     *   is tried.
     * - The person's own right counts as proven only with a verified
     *   sign-in attestation (`actor_proof`, AUTH-07).
     * - An approval that can't be proven is returned unverified: callers
     *   hold money out for review and flag money in.
     *
     * @param  array<string, mixed>|null  $override
     * @param  (Closure(User): bool)|null  $withinLimit
     */
    public function approve(User $actor, ?array $override, ?string $actorProof, string $permission, Scope $scope, ?Closure $withinLimit, Device $device, string $reference, string $field): Approval
    {
        $allowed = fn (User $user) => $this->can($user, $permission, $scope) && ($withinLimit === null || $withinLimit($user));

        if (self::hasOverride($override)) {
            $named = $override['manager_user_id'] ?? null;
            $named = $named === null ? null : $this->user($named, "{$field}.manager_user_id");

            if (($override['cashier_user_id'] ?? null) !== null) {
                $this->user($override['cashier_user_id'], "{$field}.cashier_user_id");
            }
            $proof = $this->verifier->redeem($device, $override, $permission, $reference);
            $manager = $proof === null ? $named : $this->user($proof->managerUserId, "{$field}.manager_user_id");

            if ($manager !== null && $allowed($manager)) {
                return new Approval($manager, $proof !== null && $proof->qualifies, $proof?->offline ?? false);
            }

            if (! $allowed($actor)) {
                if ($manager === null) {
                    // A token the server can't read yet: nobody to check, so it waits for review.
                    return new Approval(null, false);
                }

                throw new Rejection($this->can($manager, $permission, $scope) ? 'override_limit_exceeded' : 'override_not_permitted', "{$field}.manager_user_id");
            }
        } elseif (! $allowed($actor)) {
            throw new Rejection($this->can($actor, $permission, $scope) ? 'limit_exceeded' : 'override_required', $field);
        }

        return new Approval(null, $this->verifier->actor($device, $actor, $actorProof, $reference));
    }

    /** AUTH-07: the till proved $user was signed in for $reference. */
    public function proven(Device $device, User $user, ?string $proof, string $reference): bool
    {
        return $this->verifier->actor($device, $user, $proof, $reference);
    }

    /**
     * Users an override names must exist in the tenant, whether or not the
     * override is used: an unknown id is a bad reference.
     *
     * @param  array<string, mixed>|null  $override
     */
    public function checkNamed(?array $override, string $field): void
    {
        foreach (['manager_user_id', 'cashier_user_id'] as $key) {
            if (($override[$key] ?? null) !== null) {
                $this->user($override[$key], "{$field}.{$key}");
            }
        }
    }

    /** @param array<string, mixed>|null $override */
    public static function hasOverride(?array $override): bool
    {
        return $override !== null && (($override['token'] ?? null) !== null || ($override['manager_user_id'] ?? null) !== null);
    }

    private function isOwner(User $user, Scope $scope): bool
    {
        $roles = $this->resolver->roleIds($user, $scope);

        return $roles !== [] && Role::query()->whereKey($roles)->where('is_owner', true)->exists();
    }
}
