<?php

namespace App\Core\Limits;

/** LIM-01 until HR exists: no employees. */
final class NoEmployees implements EmployeeCount
{
    public function count(): int
    {
        return 0;
    }
}
