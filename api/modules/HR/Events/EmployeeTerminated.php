<?php

namespace Modules\HR\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** HR-05, HR-33: an employee was terminated (after commit). Payroll stops paying after `terminationDate`. */
class EmployeeTerminated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $employeeId,
        public readonly string $number,
        public readonly string $companyId,
        public readonly string $terminationDate,
        public readonly string $reason,
    ) {}
}
