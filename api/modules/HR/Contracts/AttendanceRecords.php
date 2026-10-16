<?php

namespace Modules\HR\Contracts;

use DateTimeInterface;
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
    public function closedOfCompany(string $companyId, ?DateTimeInterface $since = null): array
    {
        return AttendancePeriod::query()->where('company_id', $companyId)->where('status', AttendancePeriod::CLOSED)
            ->when($since !== null, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('start_date')->get()
            ->map(fn (AttendancePeriod $p) => [
                'period_id' => $p->id,
                'company_id' => $p->company_id,
                'start_date' => $p->start_date->toDateString(),
                'end_date' => $p->end_date->toDateString(),
                'employees' => array_values((array) $p->totals),
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])->all();
    }

    /**
     * Stage 7C closing review: the company's periods overlapping
     * [$from, $to] that are still open (a payroll run warns about them).
     *
     * @return list<array{period_id: string, start_date: string, end_date: string}>
     */
    public function openOverlapping(string $companyId, string $from, string $to): array
    {
        return AttendancePeriod::query()->where('company_id', $companyId)->where('status', '<>', AttendancePeriod::CLOSED)
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->orderBy('start_date')->get()
            ->map(fn (AttendancePeriod $p) => ['period_id' => $p->id, 'start_date' => $p->start_date->toDateString(), 'end_date' => $p->end_date->toDateString()])->all();
    }
}
