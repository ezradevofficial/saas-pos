<?php

namespace App\Core\Rbac;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\LimitRule;
use InvalidArgumentException;

/**
 * Numeric limits per role (RBAC-06). A user's limit at a scope is the
 * highest value across the roles held at scopes covering it. No rule means
 * no limit is configured, which callers treat as "not allowed".
 */
class LimitRules
{
    public const KEYS = ['max_discount_percent', 'max_refund_amount', 'max_approval_amount', 'credit_limit_override'];

    public function __construct(private readonly ScopeResolver $resolver) {}

    /** The highest value as a decimal string (4 places), or null when none is configured. */
    public function max(User $user, string $key, ?Scope $scope): ?string
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new InvalidArgumentException("Unknown limit [{$key}].");
        }

        $roleIds = $this->resolver->roleIds($user, $scope);

        if ($roleIds === []) {
            return null;
        }

        $max = LimitRule::whereIn('role_id', $roleIds)->where('key', $key)->max('value');

        // numeric(18,4) comes back as a string with 4 places: no float rounding.
        return $max === null ? null : (string) $max;
    }
}
