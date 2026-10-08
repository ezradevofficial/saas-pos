<?php

namespace App\Core\Rbac;

use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The companies, branches and locations where a user holds a permission,
 * expanded downwards (a company brings its branches and their locations).
 * `all` means a tenant-wide assignment: no filter applies (RBAC-04).
 */
final class VisibleScope
{
    /**
     * @param  list<string>  $companyIds
     * @param  list<string>  $branchIds
     * @param  list<string>  $locationIds
     */
    public function __construct(
        public readonly bool $all,
        public readonly array $companyIds = [],
        public readonly array $branchIds = [],
        public readonly array $locationIds = [],
    ) {}

    /** @return list<string> */
    public function idsFor(string $level): array
    {
        return match ($level) {
            Scope::COMPANY => $this->companyIds,
            Scope::BRANCH => $this->branchIds,
            Scope::LOCATION => $this->locationIds,
            default => throw new InvalidArgumentException("Unknown scope level [{$level}]."),
        };
    }

    /**
     * The companies this scope touches (TEN-08): the visible companies plus
     * the company of every visible branch and location, so a location
     * cashier touches that location's company. Null when `all`.
     *
     * @return list<string>|null
     */
    public function companiesTouched(): ?array
    {
        if ($this->all) {
            return null;
        }

        $branchIds = array_values(array_unique([
            ...$this->branchIds,
            ...($this->locationIds === [] ? [] : Location::query()->whereKey($this->locationIds)->pluck('branch_id')->all()),
        ]));

        return array_values(array_unique([
            ...$this->companyIds,
            ...($branchIds === [] ? [] : Branch::query()->whereKey($branchIds)->pluck('company_id')->all()),
        ]));
    }

    /**
     * Restrict $query to rows at visible $level ids. The column defaults to
     * `id` when the query is on that level's own table (companies, branches,
     * locations) and to `{level}_id` otherwise.
     */
    public function applyTo(Builder $query, string $level, ?string $column = null): Builder
    {
        $ids = $this->idsFor($level);

        if ($this->all) {
            return $query;
        }

        $column ??= $query->getModel()->getTable() === self::TABLES[$level] ? 'id' : "{$level}_id";

        return $query->whereIn($query->getModel()->qualifyColumn($column), $ids);
    }

    private const TABLES = [
        Scope::COMPANY => 'companies',
        Scope::BRANCH => 'branches',
        Scope::LOCATION => 'locations',
    ];
}
