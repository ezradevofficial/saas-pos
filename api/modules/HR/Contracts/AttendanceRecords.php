<?php

namespace Modules\HR\Contracts;

use Modules\HR\Models\AttendancePeriod;

/**
 * HR-33 (stage 7C review): a company's closed attendance periods with
 * their totals per employee, the same values AttendancePeriodClosed
 * carries, so Payroll switched on after HR (or after a lost event) gets
 * the history. Minutes only, no punches.
 */
final class AttendanceRecords
{
    /** @return list<array{period_id: string, company_id: string, start_date: string, end_date: string, employees: list<array<string, mixed>>}> */
    public function closedOfCompany(string $companyId): array
    {
        return AttendancePeriod::query()->where('company_id', $companyId)->where('status', AttendancePeriod::CLOSED)->orderBy('start_date')->get()
            ->map(fn (AttendancePeriod $p) => [
                'period_id' => $p->id,
                'company_id' => $p->company_id,
                'start_date' => $p->start_date->toDateString(),
                'end_date' => $p->end_date->toDateString(),
                'employees' => array_values((array) $p->totals),
            ])->all();
    }
}
