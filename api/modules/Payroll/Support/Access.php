<?php

namespace Modules\Payroll\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Rbac\VisibleScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * PAY-27, RBAC-04: whether a user holds a payroll permission at a record's
 * place, and the SQL that keeps a query to the places where they hold it.
 * Employee data (pay setup, items, loans, run lines, payslips) is at the
 * employee's place (location, else branch, else company); pay groups,
 * runs, returns and settings at their company.
 */
class Access
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function can(User $user, string $permission, HasScope $record): bool
    {
        return $this->resolver->can($user, $permission, $record->scope());
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

    /** Keep $query (with company, branch and location columns, or through `$via` to payroll_employees) to the places $user holds $permission at. */
    public function limitPlaces(Builder $query, User $user, string $permission, ?string $via = null): Builder
    {
        $visible = $this->visible($user, $permission);

        if ($visible->all) {
            return $query;
        }

        if ($via !== null) {
            return $query->whereIn($query->qualifyColumn($via), fn ($q) => self::placeFilter($q->select('id')->from('payroll_employees'), $visible, 'payroll_employees'));
        }

        return self::placeFilter($query, $visible, null);
    }

    /** Keep $query (with `company_id`) to companies where $permission is held at the company or above. */
    public function limitCompanies(Builder $query, User $user, string $permission): Builder
    {
        $visible = $this->visible($user, $permission);

        return $visible->all ? $query : $query->whereIn($query->qualifyColumn('company_id'), $visible->companyIds);
    }

    /** Whether $user reads company rows of $companyId with $permission (held at, above or below the company). */
    public function readsCompany(User $user, string $permission, string $companyId): bool
    {
        if ($this->resolver->can($user, $permission, Scope::company($companyId))) {
            return true;
        }

        return in_array($companyId, $this->visible($user, $permission)->companiesTouched() ?? [$companyId], true);
    }

    private static function placeFilter($query, VisibleScope $visible, ?string $table)
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
}
