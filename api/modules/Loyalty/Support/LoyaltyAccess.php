<?php

namespace Modules\Loyalty\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * RBAC-04 for loyalty records. Programmes, promotions, accounts, statements
 * and gift cards belong to a company and are company-wide by nature (a
 * customer's credit is the same at every branch). A permission held at the
 * company, or at any of its branches or locations, covers them: a branch
 * manager records a customer's payment at their branch. Nothing of a
 * company the user has no role in is visible.
 */
class LoyaltyAccess
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    /** The companies where $user holds $permission (null: every company, a tenant-wide role). @return list<string>|null */
    public function companies(User $user, string $permission): ?array
    {
        return $this->resolver->visibleIds($user, $permission)->companiesTouched();
    }

    public function allows(User $user, string $permission, string $companyId): bool
    {
        $companies = $this->companies($user, $permission);

        return $companies === null || in_array($companyId, $companies, true);
    }

    /** Whether $user holds any of $permissions anywhere. */
    public function anywhere(User $user, string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->resolver->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** $query limited to the companies where $user holds $permission. */
    public function scope(Builder $query, User $user, string $permission, string $column = 'company_id'): Builder
    {
        $companies = $this->companies($user, $permission);

        return $companies === null ? $query : $query->whereIn($query->qualifyColumn($column), $companies);
    }
}
