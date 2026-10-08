<?php

namespace App\Core\MasterData;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Visibility;

/**
 * Which companies a user reaches for a company-level resource (RBAC-04):
 * holding any of the permissions at the company, above it, or beneath it
 * (a branch manager reads the tax codes of their branch's company). Edits
 * are checked at the company itself (`$user->can(..., $company)`).
 */
class CompanyReach
{
    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly Visibility $visibility,
    ) {}

    /** @param list<string> $permissions */
    public function reaches(User $user, Company $company, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->visibility->reaches($user, $permission, $company)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ids of the companies reached, or null for all of the tenant's.
     *
     * @param  list<string>  $permissions
     * @return list<string>|null
     */
    public function companyIds(User $user, array $permissions): ?array
    {
        $ids = [];

        foreach ($permissions as $permission) {
            $touched = $this->resolver->visibleIds($user, $permission)->companiesTouched();

            if ($touched === null) {
                return null;
            }

            array_push($ids, ...$touched);
        }

        return array_values(array_unique($ids));
    }

    /**
     * TEN-08: whether the user reaches a sharable record: a shared one
     * (no company) with any of the permissions anywhere in the tenant, a
     * company's one with any of them at, above or beneath that company.
     *
     * @param  list<string>  $permissions
     */
    public function reachesRecord(User $user, ?string $companyId, array $permissions): bool
    {
        if ($companyId === null) {
            return $this->anywhere($user, $permissions);
        }

        $companies = $this->companyIds($user, $permissions);

        // Null: a tenant-wide assignment reaches every company.
        return $companies === null || in_array($companyId, $companies, true);
    }

    /** True when the user holds any of the permissions anywhere in the tenant. */
    public function anywhere(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
