<?php

namespace App\Core\Limits;

/**
 * LIM-01: the tenant's active employees. HR binds its own count when it
 * exists; until then there are none (NoEmployees).
 */
interface EmployeeCount
{
    /** Active employees of the current tenant. */
    public function count(): int;
}
