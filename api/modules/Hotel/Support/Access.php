<?php

namespace Modules\Hotel\Support;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * RBAC-04 for the Hotel module. A property is a branch: its records are seen
 * where the user holds the permission at the branch or above (a company or
 * tenant role reaches every branch). Company records (settings, room types,
 * rate plans, guests) are reached by a permission held at the company or at
 * any branch under it.
 */
class Access
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function can(User $user, string $permission, ?string $branchId = null): bool
    {
        return $this->resolver->can($user, $permission, $branchId === null ? null : Scope::branch($branchId));
    }

    /** 403 unless $user holds $permission at the branch. */
    public function require(User $user, string $permission, string $branchId): void
    {
        if (! $this->can($user, $permission, $branchId)) {
            throw new ApiException(403, 'forbidden', __('hotel.errors.place_forbidden'));
        }
    }

    public function canCompany(User $user, string $permission, string $companyId): bool
    {
        return $this->resolver->can($user, $permission, Scope::company($companyId)) || $this->allowsCompany($user, $permission, $companyId);
    }

    /** Whether $user holds $permission for the whole company (at it or above): company-wide records (room types, rates, settings) are not for one branch's staff. */
    public function canCompanyWide(User $user, string $permission, string $companyId): bool
    {
        return $this->resolver->can($user, $permission, Scope::company($companyId));
    }

    public function requireCompanyWide(User $user, string $permission, string $companyId): void
    {
        if (! $this->canCompanyWide($user, $permission, $companyId)) {
            throw new ApiException(403, 'forbidden', __('hotel.errors.place_forbidden'));
        }
    }

    /** 403 unless $user holds $permission for the company (at it, or at a branch under it). */
    public function requireCompany(User $user, string $permission, string $companyId): void
    {
        if (! $this->canCompany($user, $permission, $companyId)) {
            throw new ApiException(403, 'forbidden', __('hotel.errors.place_forbidden'));
        }
    }

    /** The companies where $user holds $permission, at or below (null: every company). @return list<string>|null */
    public function companies(User $user, string $permission): ?array
    {
        return $this->resolver->visibleIds($user, $permission)->companiesTouched();
    }

    public function allowsCompany(User $user, string $permission, string $companyId): bool
    {
        $companies = $this->companies($user, $permission);

        return $companies === null || in_array($companyId, $companies, true);
    }

    /** $query limited to the branches where $user holds $permission. */
    public function scopeBranches(Builder $query, User $user, string $permission, string $column = 'branch_id'): Builder
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn($column), $visible->branchIds);
    }

    /** $query limited to the companies where $user holds $permission. */
    public function scopeCompanies(Builder $query, User $user, string $permission, string $column = 'company_id'): Builder
    {
        $companies = $this->companies($user, $permission);

        return $companies === null ? $query : $query->whereIn($query->qualifyColumn($column), $companies);
    }

    /** Whether $user may see the record of $branchId under $permission (a list filter for single records). */
    public function sees(User $user, string $permission, string $branchId): bool
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all || in_array($branchId, $visible->branchIds, true);
    }
}
