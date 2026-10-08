<?php

namespace App\Core\MasterData;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
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
            $visible = $this->resolver->visibleIds($user, $permission);

            if ($visible->all) {
                return null;
            }

            array_push(
                $ids,
                ...$visible->companyIds,
                ...Branch::query()->whereIn('id', $visible->branchIds)->pluck('company_id')->all(),
                ...Branch::query()->whereIn('id', Location::query()->whereIn('id', $visible->locationIds)->select('branch_id'))->pluck('company_id')->all(),
            );
        }

        return array_values(array_unique($ids));
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
