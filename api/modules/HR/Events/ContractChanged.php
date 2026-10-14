<?php

namespace Modules\HR\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * HR-07, HR-33: a contract was created, changed or ended (after commit).
 * Pay is the contract's reference amount in minor units of `currency`
 * (ADR 003) per `payBasis` (monthly, daily or hourly).
 */
class ContractChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const ENDED = 'ended';

    public function __construct(
        public readonly string $tenantId,
        public readonly string $contractId,
        public readonly string $employeeId,
        public readonly string $companyId,
        public readonly string $change,
        public readonly string $type,
        public readonly string $startDate,
        public readonly ?string $endDate,
        public readonly ?string $probationEndDate,
        public readonly string $payBasis,
        public readonly string $payMinor,
        public readonly string $currency,
        public readonly ?string $hoursPerWeek,
        public readonly ?string $positionId,
        public readonly ?string $departmentId,
    ) {}
}
