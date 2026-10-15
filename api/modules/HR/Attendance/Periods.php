<?php

namespace Modules\HR\Attendance;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Modules\HR\Events\AttendancePeriodClosed;
use Modules\HR\Models\AttendanceDay;
use Modules\HR\Models\AttendancePeriod;
use Modules\HR\Models\TimeRule;
use Modules\HR\Support\Errors;

/**
 * HR-26: attendance periods. Closing recomputes the period's open days,
 * needs a time rule in force on every day of it and every day with a
 * planned shift or punches approved, then freezes the days, keeps the
 * totals per employee and raises AttendancePeriodClosed. Never reopened.
 *
 * Tills and devices queue punches offline for up to 7 days (NFR-04,
 * decision 15), so a period closes only once that window is over for its
 * last day (`period_offline_window`), unless HR forces the close with a
 * reason, which is audited (decision 25).
 */
class Periods
{
    public function __construct(
        private readonly Timesheets $timesheets,
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    /** @param array{company_id: string, start_date: string, end_date: string} $data */
    public function create(array $data, User $by): AttendancePeriod
    {
        try {
            return Errors::transaction(fn () => AttendancePeriod::create([...$data, 'status' => AttendancePeriod::OPEN, 'created_by' => $by->id]))->refresh();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw Errors::make(422, 'period_overlap', field: 'start_date');
            }

            if (($e->errorInfo[0] ?? null) === '23514') {
                throw Errors::make(422, 'period_too_long', field: 'end_date');
            }

            throw $e;
        }
    }

    public function close(AttendancePeriod $period, User $by, bool $force = false, ?string $reason = null): AttendancePeriod
    {
        if ($period->status !== AttendancePeriod::OPEN) {
            throw Errors::make(409, 'document_not_open');
        }

        $start = $period->start_date->toDateString();
        $end = $period->end_date->toDateString();
        $zone = Company::query()->whereKey($period->company_id)->value('timezone') ?: 'UTC';

        // HR-26: a period closes once its last day is over in the company's time zone.
        if (CarbonImmutable::now($zone)->toDateString() <= $end) {
            throw Errors::make(422, 'period_not_ended', ['date' => $end]);
        }

        // HR-26, NFR-04: tills and devices may still upload punches of the period's last day for 7 days.
        $windowEnds = $period->end_date->addDays(PunchUploads::OFFLINE_DAYS)->toDateString();
        $early = CarbonImmutable::now($zone)->toDateString() <= $windowEnds;

        if ($early && ! $force) {
            throw Errors::make(422, 'period_offline_window', ['date' => $windowEnds], extra: ['window_ends' => $windowEnds]);
        }

        if ($early && trim((string) $reason) === '') {
            throw Errors::make(422, 'period_force_reason', field: 'reason');
        }

        $this->assertRules($period->company_id, $start, $end);
        $this->timesheets->recomputeRange($period->company_id, $start, $end);

        return Errors::transaction(function () use ($period, $by, $start, $end, $early, $reason, $windowEnds) {
            $period = AttendancePeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if ($period->status !== AttendancePeriod::OPEN) {
                throw Errors::make(409, 'document_not_open');
            }

            $days = AttendanceDay::query()->where('company_id', $period->company_id)->whereBetween('work_date', [$start, $end])->lockForUpdate()->get();
            $unapproved = $days->whereNull('approved_at')->count();

            if ($unapproved > 0) {
                throw Errors::make(422, 'days_unapproved', ['count' => $unapproved], extra: ['count' => $unapproved]);
            }

            $totals = [];

            foreach ($days->groupBy('employee_id') as $employeeId => $rows) {
                $totals[] = [
                    'employee_id' => (string) $employeeId,
                    'days_present' => $rows->whereIn('status', ['present', 'incomplete'])->count(),
                    'days_absent' => $rows->where('status', 'absent')->count(),
                    'days_leave' => $rows->where('status', 'leave')->count(),
                    ...collect(AttendanceDay::MINUTES)->mapWithKeys(fn (string $m) => ["{$m}_minutes" => (int) $rows->sum("{$m}_minutes")])->all(),
                ];
            }

            usort($totals, fn (array $a, array $b) => strcmp($a['employee_id'], $b['employee_id']));
            AttendanceDay::query()->whereKey($days->pluck('id')->all())->update(['period_id' => $period->id, 'updated_at' => now()]);
            $period->fill(['status' => AttendancePeriod::CLOSED, 'closed_by' => $by->id, 'closed_at' => CarbonImmutable::now(), 'totals' => $totals])->save();

            if ($early) {
                $this->auditor->record('hr.attendance_period.force_close', $period, null, ['reason' => trim((string) $reason), 'offline_window_ends' => $windowEnds]);
            }

            AttendancePeriodClosed::dispatch(
                $this->tenants->require(), $period->id, $period->company_id, $start, $end,
                Company::query()->whereKey($period->company_id)->value('timezone') ?: 'UTC', $by->id, $totals,
            );

            return $period->refresh();
        });
    }

    /** HR-20: a time rule in force on every day of the range. */
    private function assertRules(string $companyId, string $start, string $end): void
    {
        $rules = TimeRule::query()->where('company_id', $companyId)->where('effective_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start))
            ->orderBy('effective_from')->get();
        $cursor = CarbonImmutable::parse($start);

        foreach ($rules as $rule) {
            if ($rule->effective_from->greaterThan($cursor)) {
                break;
            }

            if ($rule->effective_to === null) {
                return;
            }

            $cursor = $rule->effective_to->addDay()->greaterThan($cursor) ? $rule->effective_to->addDay() : $cursor;
        }

        if ($cursor->lessThanOrEqualTo(CarbonImmutable::parse($end))) {
            throw Errors::make(422, 'time_rules_missing', ['date' => $cursor->toDateString()]);
        }
    }
}
