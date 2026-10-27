<?php

namespace Modules\Pharmacy\Support;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * RBAC-04 for Pharmacy's records. Records with a location (balances,
 * prescriptions, dispenses, register entries, write-offs) are seen where the
 * user holds the permission at that location, its branch or company. Lots
 * and recalls belong to a company (or the tenant): a permission held at the
 * company, or anywhere below it, covers them.
 */
class Access
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function can(User $user, string $permission, ?string $locationId = null): bool
    {
        return $this->resolver->can($user, $permission, $locationId === null ? null : Scope::location($locationId));
    }

    /** 403 unless $user holds $permission at $locationId. */
    public function require(User $user, string $permission, string $locationId): void
    {
        if (! $this->can($user, $permission, $locationId)) {
            throw new ApiException(403, 'forbidden', __('pharmacy.errors.place_forbidden'));
        }
    }

    /**
     * 403 unless $user holds $permission for every one of $companyIds (at the
     * company or above), or tenant-wide when there are none: for acts that
     * reach those companies' records whatever the user's own place (recalls).
     *
     * @param  list<string>  $companyIds
     */
    public function requireCompanies(User $user, string $permission, array $companyIds): void
    {
        $allowed = $companyIds === []
            ? $this->resolver->can($user, $permission, Scope::tenant())
            : collect($companyIds)->every(fn (string $id) => $this->resolver->can($user, $permission, Scope::company($id)));

        if (! $allowed) {
            throw new ApiException(403, 'forbidden', __('pharmacy.errors.place_forbidden'));
        }
    }

    /** The companies where $user holds $permission (null: every company, a tenant-wide role). @return list<string>|null */
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
}
