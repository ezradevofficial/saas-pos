<?php

namespace Modules\HR\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** HR-05, HR-33: an employee was hired or rehired (after commit). Payroll starts paying from `hireDate`. */
class EmployeeHired implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $employeeId,
        public readonly string $number,
        public readonly string $companyId,
        public readonly ?string $branchId,
        public readonly ?string $locationId,
        public readonly ?string $departmentId,
        public readonly ?string $positionId,
        public readonly string $hireDate,
        public readonly bool $rehired,
    ) {}
}
