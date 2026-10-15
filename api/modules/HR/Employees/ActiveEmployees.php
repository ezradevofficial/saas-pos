<?php

namespace Modules\HR\Employees;

use App\Core\Limits\EmployeeCount;
use Modules\HR\Models\Employee;

/** HR-32, LIM-01: the tenant's active (not terminated) employees, under row-level security. */
final class ActiveEmployees implements EmployeeCount
{
    public function count(): int
    {
        return Employee::query()->where('status', Employee::ACTIVE)->count();
    }
}
