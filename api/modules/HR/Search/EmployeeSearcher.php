<?php

namespace Modules\HR\Search;

use App\Core\Search\RecordHit;
use App\Core\Search\RecordSearcher;
use App\Core\Search\SearchContext;
use App\Core\Search\SearchTerm;
use Modules\HR\HrServiceProvider;
use Modules\HR\Models\Employee;

/**
 * LAY-01, HR-01: employees by name or staff number, at the places where the
 * user holds `hr.employee.view` (RBAC-04: an employee belongs to a company,
 * branch or location). Only the name and the staff number are read: never
 * pay, bank, identity or medical data (HR-09, HR-11). Trigram indexes serve
 * the staff number and the full name.
 */
class EmployeeSearcher implements RecordSearcher
{
    public function type(): string
    {
        return 'employee';
    }

    public function module(): string
    {
        return HrServiceProvider::MODULE;
    }

    public function permission(): string
    {
        return 'hr.employee.view';
    }

    public function search(SearchTerm $term, SearchContext $context): array
    {
        $query = Employee::query()->where(function ($q) use ($term) {
            $q->whereRaw('number ilike ?', [$term->contains()])
                ->orWhereRaw("(first_name || ' ' || last_name) ~* ?", [$term->regex()]);
        });
        $context->wherePlace($query);

        return $query
            ->orderByRaw('(number ilike ?) desc, last_name, first_name', [$term->prefix()])
            ->limit($term->limit)
            ->get(['id', 'number', 'first_name', 'last_name'])
            ->map(fn (Employee $employee) => new RecordHit(
                $employee->id,
                $employee->displayName(),
                (string) $employee->number,
                "/hr/employees/{$employee->id}",
            ))
            ->all();
    }
}
