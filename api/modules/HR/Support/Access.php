<?php

namespace Modules\HR\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\VisibleScope;
use Illuminate\Database\Eloquent\Builder;
use Modules\HR\Models\Employee;

/**
 * HR-38, RBAC-04: whether a user holds a permission at an employee's
 * place, and the SQL that keeps a query to the employees (or their rows)
 * at places where the user holds it. An employee's place is its location,
 * else its branch, else its company: a branch role sees the branch's
 * employees and those of its locations, never company-level ones.
 */
class Access
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function can(User $user, string $permission, Employee $employee): bool
    {
        return $this->resolver->can($user, $permission, $employee->scope());
    }

    public function at(User $user, string $permission, Scope $scope): bool
    {
        return $this->resolver->can($user, $permission, $scope);
    }

    public function anywhere(User $user, string $permission): bool
    {
        return $this->resolver->can($user, $permission);
    }

    public function visible(User $user, string $permission): VisibleScope
    {
        return $this->resolver->visibleIds($user, $permission);
    }

    /**
     * Keep $query to rows whose place (company, branch, location columns of
     * $query's table, or of the employee when $viaEmployee) is visible.
     */
    public function limit(Builder $query, User $user, string $permission, bool $viaEmployee = false): Builder
    {
        $visible = $this->visible($user, $permission);

        if ($visible->all) {
            return $query;
        }

        if ($viaEmployee) {
            return $query->whereIn($query->qualifyColumn('employee_id'), fn ($q) => self::placeFilter($q->select('id')->from('hr_employees'), $visible, 'hr_employees'));
        }

        return self::placeFilter($query, $visible, null);
    }

    /** The place filter on a query builder (Eloquent or base), on $table's columns (or $query's model's). */
    public static function placeFilter($query, VisibleScope $visible, ?string $table)
    {
        $column = fn (string $name) => $table === null ? $query->qualifyColumn($name) : "{$table}.{$name}";

        return $query->where(function ($q) use ($visible, $column) {
            $q->whereRaw('false');

            if ($visible->locationIds !== []) {
                $q->orWhereIn($column('location_id'), $visible->locationIds);
            }

            if ($visible->branchIds !== []) {
                $q->orWhere(fn ($w) => $w->whereNull($column('location_id'))->whereIn($column('branch_id'), $visible->branchIds));
            }

            if ($visible->companyIds !== []) {
                $q->orWhere(fn ($w) => $w->whereNull($column('branch_id'))->whereNull($column('location_id'))->whereIn($column('company_id'), $visible->companyIds));
            }
        });
    }

    /** Keep a query on company-level setup rows (`company_id`) to companies where $permission is held at or above. */
    public function limitCompanies(Builder $query, User $user, string $permission): Builder
    {
        $visible = $this->visible($user, $permission);

        if ($visible->all) {
            return $query;
        }

        return $query->whereIn($query->qualifyColumn('company_id'), $visible->companyIds);
    }

    /** Companies where $user may read company setup with $permission (held at the company or above, or below it: they read the setup of their places). */
    public function readsCompany(User $user, string $permission, string $companyId): bool
    {
        if ($this->resolver->can($user, $permission, Scope::company($companyId))) {
            return true;
        }

        return in_array($companyId, $this->visible($user, $permission)->companiesTouched() ?? [$companyId], true);
    }
}
