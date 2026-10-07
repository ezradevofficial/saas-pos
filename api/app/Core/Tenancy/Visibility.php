<?php

namespace App\Core\Tenancy;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;

/**
 * Whether a user reaches a company, branch or location for a permission
 * (RBAC-04): the user holds it there, above it, or somewhere beneath it. A
 * Branch Manager of branch A reaches A's company for `core.branch.view`
 * (to list and try to create its branches) but not branch B.
 *
 * Parents that are not reached answer 404, so ids outside the user's scope
 * are never confirmed.
 */
class Visibility
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function reaches(User $user, string $permission, Company|Branch|Location $node): bool
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        if ($visible->all) {
            return true;
        }

        return match (true) {
            $node instanceof Company => in_array($node->id, $visible->companyIds, true)
                || Branch::where('company_id', $node->id)->whereIn('id', $visible->branchIds)->exists()
                || Location::whereIn('id', $visible->locationIds)
                    ->whereHas('branch', fn ($q) => $q->where('company_id', $node->id))
                    ->exists(),
            $node instanceof Branch => in_array($node->id, $visible->branchIds, true)
                || Location::where('branch_id', $node->id)->whereIn('id', $visible->locationIds)->exists(),
            $node instanceof Location => in_array($node->id, $visible->locationIds, true),
        };
    }
}
