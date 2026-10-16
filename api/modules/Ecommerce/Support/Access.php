<?php

namespace Modules\Ecommerce\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * RBAC-04: where a user may act in the module. Orders, deliveries and
 * stores are scoped by their location (a branch or company role covers the
 * locations under it); riders and cash-ups by their company. Out of scope
 * is not found (404) in lists and reads, refused (403) for writes at a
 * place the user names.
 */
final class Access
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function can(User $user, string $permission, ?Scope $at = null): bool
    {
        return $this->resolver->can($user, $permission, $at);
    }

    public function atLocation(User $user, string $permission, string $locationId): bool
    {
        return $this->resolver->can($user, $permission, Scope::location($locationId));
    }

    public function atCompany(User $user, string $permission, string $companyId): bool
    {
        return $this->resolver->can($user, $permission, Scope::company($companyId));
    }

    /** @throws AuthorizationException */
    public function assertAtLocation(User $user, string $permission, string $locationId): void
    {
        if (! $this->atLocation($user, $permission, $locationId)) {
            throw new AuthorizationException(__('ecommerce.errors.forbidden'));
        }
    }

    /** @throws AuthorizationException */
    public function assertAtCompany(User $user, string $permission, string $companyId): void
    {
        if (! $this->atCompany($user, $permission, $companyId)) {
            throw new AuthorizationException(__('ecommerce.errors.forbidden'));
        }
    }

    /** Restrict $query to rows at locations where $user holds $permission. */
    public function byLocation(Builder $query, User $user, string $permission, string $column = 'location_id'): Builder
    {
        return $this->resolver->visibleIds($user, $permission)->applyTo($query, Scope::LOCATION, $column);
    }

    /** Restrict $query to rows of companies $user's $permission touches (TEN-08). */
    public function byCompany(Builder $query, User $user, string $permission, string $column = 'company_id'): Builder
    {
        $scope = $this->resolver->visibleIds($user, $permission);

        return $scope->all ? $query : $query->whereIn($query->getModel()->qualifyColumn($column), $scope->companiesTouched() ?? []);
    }
}
