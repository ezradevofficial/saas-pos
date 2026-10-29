<?php

namespace App\Core\Search;

use App\Core\Identity\Models\User;
use App\Core\Rbac\FieldRules;
use App\Core\Rbac\VisibleScope;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * What a searcher may see (RBAC-04, RBAC-05): the user, the places where they
 * hold the searcher's permission, and their field rules.
 */
final class SearchContext
{
    /** @var array<string, list<string>> resource => hidden fields */
    private array $hidden = [];

    public function __construct(
        public readonly User $user,
        public readonly VisibleScope $visible,
        private readonly FieldRules $fieldRules,
    ) {}

    /** True when the user's field rules hide $field of $resource (RBAC-05): never match or show it. */
    public function hides(string $resource, string $field): bool
    {
        $this->hidden[$resource] ??= $this->fieldRules->for($this->user, $resource)['hidden'];

        return in_array($field, $this->hidden[$resource], true);
    }

    /** Restrict $query to rows at a visible company, branch or location id (RBAC-04). */
    public function whereAt(EloquentBuilder|Builder $query, string $level, string $column): void
    {
        if (! $this->visible->all) {
            $query->whereIn($column, $this->visible->idsFor($level));
        }
    }

    /**
     * Restrict $query to rows whose place is visible, where the place is the
     * most specific of location, branch and company that is set (the shape
     * of Scope::location, ::branch and ::company).
     */
    public function wherePlace(EloquentBuilder|Builder $query, string $company = 'company_id', string $branch = 'branch_id', string $location = 'location_id'): void
    {
        if ($this->visible->all) {
            return;
        }

        $v = $this->visible;
        $query->where(function ($q) use ($v, $company, $branch, $location) {
            $q->whereIn($location, $v->locationIds)
                ->orWhere(fn ($b) => $b->whereNull($location)->whereIn($branch, $v->branchIds))
                ->orWhere(fn ($c) => $c->whereNull($location)->whereNull($branch)->whereIn($company, $v->companyIds));
        });
    }
}
