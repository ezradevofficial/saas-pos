<?php

namespace App\Core\Rbac;

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
