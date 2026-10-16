<?php

namespace Modules\Manufacturing\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Builder;

/**
 * TEN-04, RBAC-04 (MFG-21): where a location sits (branch and company),
 * read under RLS (another tenant's location is not found); orders and
 * their documents are checked at their location; BOMs, settings and
 * reports by company are company data, reached by a holder of the
 * permission anywhere in the company.
 */
class Places
{
    /** @var array<string, ?array{company_id: string, branch_id: string, type: string, archived: bool}> */
    private array $cache = [];

    public function __construct(private readonly ScopeResolver $resolver) {}

    /** @return array{company_id: string, branch_id: string, type: string, archived: bool}|null */
    public function of(string $locationId): ?array
    {
        if (array_key_exists($locationId, $this->cache)) {
            return $this->cache[$locationId];
        }

        $row = Location::query()->join('branches', 'branches.id', '=', 'locations.branch_id')
            ->where('locations.id', $locationId)
            ->first(['branches.company_id', 'locations.branch_id', 'locations.type', 'locations.archived_at']);

        return $this->cache[$locationId] = $row === null ? null : [
            'company_id' => (string) $row->company_id,
            'branch_id' => (string) $row->branch_id,
            'type' => (string) $row->type,
            'archived' => $row->archived_at !== null,
        ];
    }

    /** A location a document may be made at (422 otherwise, on $field). @return array{company_id: string, branch_id: string, type: string, archived: bool} */
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

    /** Whether $user holds $permission at $companyId itself (or tenant-wide): changing company data (review fix 3). */
    public function atCompany(User $user, string $permission, string $companyId): bool
    {
        return $this->resolver->can($user, $permission, Scope::company($companyId));
    }

    /** Whether $user holds $permission anywhere in $companyId (at the company, a branch or a location of it). */
    public function inCompany(User $user, string $permission, string $companyId): bool
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all || in_array($companyId, $visible->companiesTouched() ?? [], true);
    }

    /** $query limited to rows of the companies where $user holds $permission somewhere. */
    public function filterCompanies(Builder $query, User $user, string $permission, string $column = 'company_id'): Builder
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn($column), $visible->companiesTouched() ?? []);
    }

    /** $query limited to rows at the locations where $user holds $permission. */
    public function filterLocations(Builder $query, User $user, string $permission, string $column = 'location_id'): Builder
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn($column), $visible->idsFor(Scope::LOCATION));
    }
}
