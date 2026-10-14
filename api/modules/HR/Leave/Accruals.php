<?php

namespace Modules\HR\Leave;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveType;
use Modules\HR\Support\Errors;

/**
 * HR-13: leave accrual, idempotent (one ledger entry per employee, type
 * and period key) so it runs daily and on demand.
 *
 * - `annual`: the year's entitlement on 1 January (key `accrual:YYYY`),
 *   pro-rated by the days employed in the year ÷ the days in the year
 *   (from the later of the hire date and the type's `accrues_from`,
 *   to the termination date), effective on the first day employed.
 * - `monthly`: entitlement ÷ 12 at each month end (`accrual:YYYY-MM`),
 *   pro-rated by the days employed in the month ÷ the days in the month;
 *   only months that ended on or before the run date.
 * - Expiry: on 1 January of each year after the first, a balance on
 *   31 December above the type's carry-over cap expires (`expiry:YYYY`).
 *
 * Amounts round half up to 2 decimals. Types that need confirmation are
 * skipped and reported (HR-12): days are never guessed.
 */
class Accruals
{
    public function __construct(private readonly LeaveLedger $ledger) {}

    /**
     * @return array{posted: int, skipped_types: list<array{id: string, code: string}>}
     */
    public function run(CarbonImmutable $asOf, ?string $companyId = null): array
    {
        $posted = 0;
        $skipped = [];
        $types = LeaveType::query()->whereNull('archived_at')->whereIn('accrual', ['annual', 'monthly'])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('company_id')->orderBy('code')->get();

        foreach ($types as $type) {
            if (! $type->usable() || $type->entitlement_days === null) {
                $skipped[] = ['id' => $type->id, 'code' => $type->code];

                continue;
            }

            $employees = Employee::query()->where('company_id', $type->company_id)->where('hire_date', '<=', $asOf->toDateString())
                ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', ($type->accrues_from ?? $asOf)->toDateString()))
                ->orderBy('id')->get(['id', 'hire_date', 'termination_date']);

            foreach ($employees as $employee) {
                $posted += Errors::transaction(fn () => $this->employee($employee, $type, $asOf));
            }
        }

        return ['posted' => $posted, 'skipped_types' => $skipped];
    }

    /** The entries due for one employee and type up to $asOf; returns how many were posted now. */
    public function employee(Employee $employee, LeaveType $type, CarbonImmutable $asOf): int
    {
        $from = $employee->hire_date;
        $accruesFrom = $type->accrues_from ?? $type->created_at->toImmutable()->startOfDay();

        if ($accruesFrom->greaterThan($from)) {
            $from = $accruesFrom;
        }

        $until = $employee->termination_date !== null && $employee->termination_date->lessThan($asOf) ? $employee->termination_date : $asOf;

        if ($from->greaterThan($until)) {
            return 0;
        }

        $posted = 0;
        $entitlement = BigDecimal::of((string) $type->entitlement_days);

        for ($year = $from->year; $year <= $asOf->year; $year++) {
            $yearStart = CarbonImmutable::create($year, 1, 1);
            $yearEnd = $yearStart->endOfYear()->startOfDay();

            // Expiry of last year's balance above the cap, before this year's credits.
            if ($year > $from->year && $type->carry_over_max_days !== null && $yearStart->lessThanOrEqualTo($asOf)) {
                $excess = BigDecimal::of($this->ledger->balance($employee->id, $type->id, $yearStart->subDay()))->minus(BigDecimal::of((string) $type->carry_over_max_days));

                if ($excess->isPositive()) {
                    $posted += $this->ledger->post($employee->id, $type->id, 'expiry', (string) $excess->negated(), $yearStart->toDateString(), "expiry:{$year}") === null ? 0 : 1;
                }
            }

            $start = $from->greaterThan($yearStart) ? $from : $yearStart;
            // The year's employment as known now: to the termination date, else the year's end.
            $last = $employee->termination_date !== null && $employee->termination_date->lessThan($yearEnd) ? $employee->termination_date : $yearEnd;
            $end = $type->accrual === 'annual' ? $last : ($until->lessThan($last) ? $until : $last);

            if ($start->greaterThan($end) || $start->greaterThan($asOf)) {
                continue;
            }

            if ($type->accrual === 'annual') {
                $daysInYear = $yearStart->daysInYear;
                $employed = (int) $start->diffInDays($end) + 1;
                $amount = $employed >= $daysInYear ? $entitlement : $entitlement->multipliedBy($employed)->dividedBy($daysInYear, 10, RoundingMode::HalfUp);
                $posted += $this->ledger->post($employee->id, $type->id, 'accrual', (string) $amount->toScale(2, RoundingMode::HalfUp), $start->toDateString(), "accrual:{$year}") === null ? 0 : 1;

                continue;
            }

            // Monthly: each month that ended on or before the run date.
            for ($month = $start->startOfMonth(); $month->lessThanOrEqualTo($end); $month = $month->addMonth()) {
                $monthEnd = $month->endOfMonth()->startOfDay();

                if ($monthEnd->greaterThan($asOf)) {
                    break;
                }

                $s = $start->greaterThan($month) ? $start : $month;
                $e = $end->lessThan($monthEnd) ? $end : $monthEnd;

                if ($s->greaterThan($e)) {
                    continue;
                }

                $daysInMonth = $month->daysInMonth;
                $employed = (int) $s->diffInDays($e) + 1;
                $monthly = $entitlement->dividedBy(12, 10, RoundingMode::HalfUp);
                $amount = $employed >= $daysInMonth ? $monthly : $monthly->multipliedBy($employed)->dividedBy($daysInMonth, 10, RoundingMode::HalfUp);
                $posted += $this->ledger->post($employee->id, $type->id, 'accrual', (string) $amount->toScale(2, RoundingMode::HalfUp), $monthEnd->toDateString(), 'accrual:'.$month->format('Y-m')) === null ? 0 : 1;
            }
        }

        return $posted;
    }
}
