<?php

namespace App\Core\Rbac;

use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Assignments seen through a user's visible scope (RBAC-04), and the names
 * of the scopes they point at, for lists and the access review.
 */
class ScopeNames
{
    private const MODELS = [
        Scope::COMPANY => Company::class,
        Scope::BRANCH => Branch::class,
        Scope::LOCATION => Location::class,
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Fill $scopeName on each assignment, with one query per scope level.
     *
     * @param  iterable<RoleAssignment>  $assignments
     */
    public function attach(iterable $assignments): void
    {
        $assignments = collect($assignments);
        $names = [];

        foreach (self::MODELS as $type => $class) {
            $ids = $assignments->where('scope_type', $type)->pluck('scope_id')->unique()->values()->all();

            if ($ids !== []) {
                $names[$type] = $class::whereKey($ids)->pluck('name', 'id')->all();
            }
        }

        if ($assignments->contains('scope_type', Scope::TENANT)) {
            $names[Scope::TENANT] = Tenant::whereKey($this->tenants->require())->pluck('name', 'id')->all();
        }

        foreach ($assignments as $assignment) {
            $assignment->scopeName = $names[$assignment->scope_type][$assignment->scope_id] ?? null;
        }
    }

    /**
     * Restrict a role_assignments query to scopes $visible covers. A
     * tenant-scope assignment is covered only by a tenant-wide view.
     */
    public static function constrain(Builder $query, VisibleScope $visible): Builder
    {
        if ($visible->all) {
            return $query;
        }

        $type = $query->qualifyColumn('scope_type');
        $id = $query->qualifyColumn('scope_id');

        return $query->where(function (Builder $q) use ($visible, $type, $id) {
            $q->whereRaw('false');

            foreach ([Scope::COMPANY, Scope::BRANCH, Scope::LOCATION] as $level) {
                $ids = $visible->idsFor($level);

                if ($ids !== []) {
                    $q->orWhere(fn (Builder $q) => $q->where($type, $level)->whereIn($id, $ids));
                }
            }
        });
    }

    /** True when $visible covers the scope $type:$id. */
    public static function covers(VisibleScope $visible, string $type, string $id): bool
    {
        if ($visible->all) {
            return true;
        }

        return $type !== Scope::TENANT && in_array($id, $visible->idsFor($type), true);
    }
}
