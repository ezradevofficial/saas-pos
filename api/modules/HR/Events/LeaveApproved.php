<?php

namespace Modules\HR\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HR-14, HR-33: a leave request was approved (after commit). `dates` lists
 * each day counted with its fraction ("1.00" or "0.50"); Payroll deducts
 * unpaid leave (`paid` false) by date.
 */
class LeaveApproved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<array{date: string, fraction: string}> $dates */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $requestId,
        public readonly string $number,
        public readonly string $employeeId,
        public readonly string $companyId,
        public readonly string $leaveTypeId,
        public readonly string $leaveTypeCode,
        public readonly bool $paid,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly bool $startHalf,
        public readonly bool $endHalf,
        public readonly string $days,
        public readonly array $dates,
    ) {}
}
