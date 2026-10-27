<?php

namespace Modules\FuelStation\Support;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * RBAC-04 for the Fuel station module. A station is a location: its records
 * are seen and changed where the user holds the permission at the location,
 * its branch or its company (a tenant-wide role reaches every station).
 * Company records (settings, products, vehicles, vouchers) are reached by a
 * permission held at the company or anywhere below it, and changed only by
 * a permission held for the whole company.
 */
class Access
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function can(User $user, string $permission, ?string $locationId = null): bool
    {
        return $this->resolver->can($user, $permission, $locationId === null ? null : Scope::location($locationId));
    }

    /** 403 unless $user holds $permission at the station (location). */
    public function require(User $user, string $permission, string $locationId): void
    {
        if (! $this->can($user, $permission, $locationId)) {
            throw new ApiException(403, 'forbidden', __('fuel.errors.place_forbidden'));
        }
    }

    public function canCompany(User $user, string $permission, string $companyId): bool
    {
        return $this->resolver->can($user, $permission, Scope::company($companyId)) || $this->allowsCompany($user, $permission, $companyId);
    }

    /** Whether $user holds $permission for the whole company (at it or above). */
    public function canCompanyWide(User $user, string $permission, string $companyId): bool
    {
        return $this->resolver->can($user, $permission, Scope::company($companyId));
    }

    public function requireCompany(User $user, string $permission, string $companyId, bool $wide = false): void
    {
        if (! ($wide ? $this->canCompanyWide($user, $permission, $companyId) : $this->canCompany($user, $permission, $companyId))) {
            throw new ApiException(403, 'forbidden', __('fuel.errors.place_forbidden'));
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

    /** $query limited to the companies where $user holds $permission. */
    public function scopeCompanies(Builder $query, User $user, string $permission, string $column = 'company_id'): Builder
    {
        $companies = $this->companies($user, $permission);

        return $companies === null ? $query : $query->whereIn($query->qualifyColumn($column), $companies);
    }

    /** $query limited to the stations (locations) where $user holds $permission. */
    public function scopeStations(Builder $query, User $user, string $permission, string $column = 'location_id'): Builder
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn($column), $visible->locationIds);
    }

    /** Whether $user may see a record of the station under $permission. */
    public function sees(User $user, string $permission, string $locationId): bool
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all || in_array($locationId, $visible->locationIds, true);
    }
}
