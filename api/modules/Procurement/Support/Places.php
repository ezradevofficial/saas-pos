<?php

namespace Modules\Procurement\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Builder;

/**
 * RBAC-04 for Procurement (PRC-22): documents live at a branch (and,
 * for POs, requisitions and GRNs, a location). A user sees one where they
 * hold the permission at its branch (or above), or at its location; lists
 * keep the same rows. GRNs are checked at their location.
 */
class Places
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function sees(User $user, string $permission, string $branchId, ?string $locationId = null): bool
    {
        return $this->resolver->can($user, $permission, Scope::branch($branchId))
            || ($locationId !== null && $this->resolver->can($user, $permission, Scope::location($locationId)));
    }

    public function at(User $user, string $permission, string $branchId): bool
    {
        return $this->resolver->can($user, $permission, Scope::branch($branchId));
    }

    public function atLocation(User $user, string $permission, string $locationId): bool
    {
        return $this->resolver->can($user, $permission, Scope::location($locationId));
    }

    /** $query limited to the rows the user sees with $permission (by branch, and by location when the table has one). */
    public function filter(Builder $query, User $user, string $permission, string $branchColumn = 'branch_id', ?string $locationColumn = null): Builder
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        if ($visible->all) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($visible, $branchColumn, $locationColumn) {
            $q->whereIn($q->qualifyColumn($branchColumn), $visible->idsFor(Scope::BRANCH));

            if ($locationColumn !== null) {
                $q->orWhereIn($q->qualifyColumn($locationColumn), $visible->idsFor(Scope::LOCATION));
            }
        });
    }

    /** The branch, its company and time zone; null for an unknown id (RLS: another tenant's does not exist). */
    public function branch(string $branchId): ?Branch
    {
        return Branch::query()->with('company')->find($branchId);
    }

    public function location(string $locationId): ?Location
    {
        return Location::query()->with('branch.company')->find($locationId);
    }
}
