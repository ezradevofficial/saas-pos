<?php

namespace Modules\Restaurant\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Builder;

/**
 * TEN-04, RBAC-04: where a location sits (branch and company), read under
 * RLS (another tenant's location is not found); floor, stations and orders
 * are checked at their location, settings and modifiers by company.
 */
class Places
{
    /** @var array<string, ?array{company_id: string, branch_id: string, archived: bool}> */
    private array $cache = [];

    public function __construct(private readonly ScopeResolver $resolver) {}

    /** @return array{company_id: string, branch_id: string, archived: bool}|null */
    public function of(string $locationId): ?array
    {
        if (array_key_exists($locationId, $this->cache)) {
            return $this->cache[$locationId];
        }

        $row = Location::query()->join('branches', 'branches.id', '=', 'locations.branch_id')
            ->where('locations.id', $locationId)
            ->first(['branches.company_id', 'locations.branch_id', 'locations.archived_at']);

        return $this->cache[$locationId] = $row === null ? null : [
            'company_id' => (string) $row->company_id,
            'branch_id' => (string) $row->branch_id,
            'archived' => $row->archived_at !== null,
        ];
    }

    /** A location a record may be made at (422 otherwise, on $field). @return array{company_id: string, branch_id: string, archived: bool} */
    public function open(string $locationId, string $field = 'location_id'): array
    {
        $place = $this->of($locationId) ?? throw Errors::make(422, 'location_unknown', field: $field);

        if ($place['archived']) {
            throw Errors::make(422, 'location_archived', field: $field);
        }

        return $place;
    }

    public function at(User $user, string $permission, string $locationId): bool
    {
        return $this->resolver->can($user, $permission, Scope::location($locationId));
    }

    public function atCompany(User $user, string $permission, string $companyId): bool
    {
        return $this->resolver->can($user, $permission, Scope::company($companyId));
    }

    /** Whether $user holds $permission anywhere in $companyId. */
    public function inCompany(User $user, string $permission, string $companyId): bool
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all || in_array($companyId, $visible->companiesTouched() ?? [], true);
    }

    /** $query limited to rows at the locations where $user holds $permission. */
    public function filterLocations(Builder $query, User $user, string $permission, string $column = 'location_id'): Builder
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn($column), $visible->idsFor(Scope::LOCATION));
    }

    /** $query limited to rows of the companies where $user holds $permission somewhere. */
    public function filterCompanies(Builder $query, User $user, string $permission, string $column = 'company_id'): Builder
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn($column), $visible->companiesTouched() ?? []);
    }
}
