<?php

namespace Modules\FixedAssets\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Modules\FixedAssets\Models\Asset;

/**
 * FA-29, RBAC-04: what a user may do with assets, at the asset's location
 * (a branch or company role covers its locations), and with company-wide
 * records (categories, settings, runs, reports) at the company.
 */
class Access
{
    public function __construct(private readonly ScopeResolver $scopes) {}

    public function at(User $user, string $permission, Asset|string|null $place): bool
    {
        $location = $place instanceof Asset ? $place->location_id : $place;

        return $this->scopes->can($user, $permission, $location === null ? null : Scope::location($location));
    }

    public function atCompany(User $user, string $permission, string $companyId): bool
    {
        return $this->scopes->can($user, $permission, Scope::company($companyId));
    }

    public function anywhere(User $user, string $permission): bool
    {
        return $this->scopes->can($user, $permission);
    }

    /** $query (of a table with `location_id`) limited to the locations where $user holds $permission. */
    public function byLocation(Builder $query, User $user, string $permission, string $column = 'location_id'): Builder
    {
        $visible = $this->scopes->visibleIds($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn($column), $visible->idsFor(Scope::LOCATION));
    }

    /** $query (of a table with `asset_id`) limited to assets at locations where $user holds $permission. */
    public function byAsset(Builder $query, User $user, string $permission): Builder
    {
        $visible = $this->scopes->visibleIds($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn('asset_id'),
            fn ($q) => $q->select('id')->from('fa_assets')->whereIn('location_id', $visible->idsFor(Scope::LOCATION)));
    }

    /** $query (of a table with `company_id`) limited to companies where $user holds $permission at the company. */
    public function byCompany(Builder $query, User $user, string $permission): Builder
    {
        $visible = $this->scopes->visibleIds($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn('company_id'), $visible->idsFor(Scope::COMPANY));
    }
}
