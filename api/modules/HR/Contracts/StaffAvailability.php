<?php

namespace Modules\HR\Contracts;

use DateTimeInterface;
use Illuminate\Support\Str;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\RosterShift;

/**
 * A read contract for other modules (Salon's staff calendar, SAL-04): when an
 * employee is planned to work and when they are on approved leave, as plain
 * data read under the tenant's row-level security. Only times, never a leave
 * reason, type or document (HR-09). Callers use it only while HR is active for
 * the tenant.
 */
final class StaffAvailability
{
    /**
     * Planned (not cancelled) roster shifts that overlap [$from, $to).
     *
     * @param  list<string>  $employeeIds
     * @return list<array{employee_id: string, branch_id: ?string, start_at: string, end_at: string, break_minutes: int}>
     */
    public function shifts(array $employeeIds, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $ids = self::ids($employeeIds);

        if ($ids === []) {
            return [];
        }

        return RosterShift::query()->whereIn('employee_id', $ids)->where('status', RosterShift::PLANNED)
            ->where('start_at', '<', $to)->where('end_at', '>', $from)
            ->orderBy('start_at')->orderBy('id')->get()
            ->map(fn (RosterShift $shift) => [
                'employee_id' => (string) $shift->employee_id,
                'branch_id' => $shift->branch_id,
                'start_at' => $shift->start_at->toIso8601String(),
                'end_at' => $shift->end_at->toIso8601String(),
                'break_minutes' => (int) $shift->break_minutes,
            ])->all();
    }

    /**
     * Dates (YYYY-MM-DD, the employee's own calendar days) of approved leave between two dates, any fraction of a day.
     *
     * @param  list<string>  $employeeIds
     * @return list<array{employee_id: string, date: string}>
     */
    public function leaveDays(array $employeeIds, string $fromDate, string $toDate): array
    {
        $ids = self::ids($employeeIds);

        if ($ids === []) {
            return [];
        }

        $days = [];

        LeaveRequest::query()->whereIn('employee_id', $ids)->where('status', LeaveRequest::APPROVED)
            ->whereDate('start_date', '<=', $toDate)->whereDate('end_date', '>=', $fromDate)
            ->orderBy('start_date')->get(['employee_id', 'dates'])
            ->each(function (LeaveRequest $request) use (&$days, $fromDate, $toDate) {
                foreach ((array) $request->dates as $day) {
                    $date = (string) ($day['date'] ?? '');

                    if ($date >= $fromDate && $date <= $toDate) {
                        $days[] = ['employee_id' => (string) $request->employee_id, 'date' => $date];
                    }
                }
            });

        return $days;
    }

    /** @param  list<string>  $ids @return list<string> */
    private static function ids(array $ids): array
    {
        return array_values(array_filter(array_unique($ids), fn ($id) => is_string($id) && Str::isUuid($id)));
    }
}
