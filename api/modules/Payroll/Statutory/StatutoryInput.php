<?php

namespace Modules\Payroll\Statutory;

/**
 * PAY-07: what the statutory engine computes from, for one employee and
 * pay period, in minor units of the pack currency (digit strings):
 * earnings by base, pre-tax deductions and insurance premiums, the
 * employee's dependants and children, the company's headcount, and the
 * factor applied to the pack's monthly figures (1 for monthly pay; 12 ÷
 * periods a year when the pack says `non_monthly: pro_rata`).
 */
final class StatutoryInput
{
    public function __construct(
        public readonly string $gross,
        public readonly string $taxableGross,
        public readonly string $contributoryGross,
        public readonly string $basic,
        public readonly string $preTax = '0',
        public readonly string $insurancePremiums = '0',
        public readonly int $dependants = 0,
        public readonly int $children = 0,
        public readonly int $headcount = 1,
        public readonly string $scale = '1',
    ) {}
}
