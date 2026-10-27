<?php

namespace Modules\Salon\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * TEN-04, RBAC-04: where a branch or location sits (company, time zone), read under RLS (another tenant's branch is not
 * found); every record is checked at its branch or location, settings and rules by company.
 */
class Places
{
    /** @var array<string, ?Branch> */
    private array $branches = [];

    /** @var array<string, string> company id => time zone */
    private array $companyZones = [];

    public function __construct(private readonly ScopeResolver $resolver) {}

    public function branch(string $id): ?Branch
    {
        return array_key_exists($id, $this->branches) ? $this->branches[$id] : $this->branches[$id] = Branch::query()->find($id);
    }

    /** A live branch (422 otherwise, on $field). */
    public function openBranch(string $id, string $field = 'branch_id'): Branch
    {
        $branch = $this->branch($id) ?? throw Errors::make(422, 'branch_unknown', field: $field);

        if ($branch->archived_at !== null) {
            throw Errors::make(422, 'branch_archived', field: $field);
        }

        return $branch;
    }

    /** A live location of $branchId (422 otherwise, on $field). */
    public function openLocation(string $locationId, string $branchId, string $field = 'location_id'): Location
    {
        $location = Location::query()->find($locationId);

        if ($location === null || $location->branch_id !== $branchId || $location->archived_at !== null) {
            throw Errors::make(422, 'location_unknown', field: $field);
        }

        return $location;
    }

    /** The IANA time zone of a branch (else its company, else UTC): where "a day" and "9:00" are. */
    public function zone(Branch|string $branch): string
    {
        $branch = $branch instanceof Branch ? $branch : $this->branch($branch);

        if ($branch === null) {
            return 'UTC';
        }

        return $branch->timezone ?: ($this->companyZones[$branch->company_id] ??= (string) (Company::query()->whereKey($branch->company_id)->value('timezone') ?: 'UTC'));
    }

    /** The UTC instants a local day of the branch starts and ends at. @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function day(Branch|string $branch, string $date): array
    {
        $start = CarbonImmutable::parse($date.' 00:00:00', $this->zone($branch));

        return [$start->utc(), $start->addDay()->utc()];
    }

    public function at(User $user, string $permission, Scope $scope): bool
    {
        return $this->resolver->can($user, $permission, $scope);
    }

    /** Whether $user holds $permission anywhere in $companyId. */
    public function inCompany(User $user, string $permission, string $companyId): bool
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all || in_array($companyId, $visible->companiesTouched() ?? [], true);
    }

    /** $query limited to rows of the branches where $user holds $permission (a company brings its branches). */
    public function filterBranches(Builder $query, User $user, string $permission, string $column = 'branch_id'): Builder
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn($column), $visible->idsFor(Scope::BRANCH));
    }

    /**
     * $query limited to rows at the branches (or, for a record made at a location, the locations) where $user holds
     * $permission: a branch role covers its locations, a location role only its own.
     */
    public function filterPlaces(Builder $query, User $user, string $permission, string $branchColumn = 'branch_id', string $locationColumn = 'location_id'): Builder
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        if ($visible->all) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereIn($query->qualifyColumn($branchColumn), $visible->idsFor(Scope::BRANCH))
            ->orWhereIn($query->qualifyColumn($locationColumn), $visible->idsFor(Scope::LOCATION)));
    }

    /** The companies where $user holds $permission somewhere; null for every company. @return list<string>|null */
    public function companyIds(User $user, string $permission): ?array
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all ? null : ($visible->companiesTouched() ?? []);
    }

    /** $query limited to rows of the companies where $user holds $permission somewhere. */
    public function filterCompanies(Builder $query, User $user, string $permission, string $column = 'company_id'): Builder
    {
        $visible = $this->resolver->visibleIds($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn($column), $visible->companiesTouched() ?? []);
    }
}
