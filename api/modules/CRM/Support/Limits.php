<?php

namespace Modules\CRM\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\LimitRules;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Brick\Math\BigDecimal;

/**
 * RBAC-06 for CRM (CRM-20): a user's limit at a branch is the highest of
 * the roles they hold there; no rule means not allowed; an Owner role
 * covering the branch has no limit.
 */
class Limits
{
    public function __construct(
        private readonly LimitRules $limits,
        private readonly ScopeResolver $resolver,
    ) {}

    /** The user's `max_discount_percent` at the branch: null when none (no discount), `unlimited` for an Owner. */
    public function maxDiscount(User $user, string $branchId): ?string
    {
        $scope = Scope::branch($branchId);

        if ($this->isOwner($user, $scope)) {
            return 'unlimited';
        }

        return $this->limits->max($user, 'max_discount_percent', $scope);
    }

    public function withinDiscount(User $user, string $branchId, BigDecimal $percent): bool
    {
        $max = $this->maxDiscount($user, $branchId);

        return $max === 'unlimited' || ($max !== null && $percent->isLessThanOrEqualTo($max));
    }

    private function isOwner(User $user, Scope $scope): bool
    {
        $roles = $this->resolver->roleIds($user, $scope);

        return $roles !== [] && Role::query()->whereKey($roles)->where('is_owner', true)->exists();
    }
}
