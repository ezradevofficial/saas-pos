<?php

namespace Modules\Payroll\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * PAY-17, PAY-22: a run was posted (after commit). `groups` carries what a
 * journal needs, per department and pay currency, from the run's lines
 * alone: `{key, department_id, branch_id, currency, fx (the pay currency
 * to the company's base, CUR-04), employees, amounts: {gross, net_pay,
 * statutory.{code}, employer.{code}, deduction.{code}, loans}}`, signed
 * minor units as digit strings (a correction's are differences). For every
 * group, gross = Σ statutory + Σ deduction + loans + net_pay, and the
 * employer contributions appear on both sides of the journal.
 */
class PayrollPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param list<array<string, mixed>> $groups */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $runId,
        public readonly string $number,
        public readonly string $companyId,
        public readonly string $kind,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly string $payDate,
        public readonly array $groups,
    ) {}
}
