<?php

namespace Modules\HR\Rosters;

use App\Core\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\HR\Attendance\Timesheets;
use Modules\HR\Models\Employee;
use Modules\HR\Models\RosterShift;
use Modules\HR\Models\Shift;
use Modules\HR\Support\Errors;

/** HR-18: planned shifts added, moved or cancelled by hand (one planned per employee and date). */
class RosterShifts
{
    public function __construct(
        private readonly Schedules $schedules,
        private readonly Timesheets $timesheets,
    ) {}

    /** @param array{employee_id: string, date: string, shift_id: string} $data */
    public function create(array $data, User $by): RosterShift
    {
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $shift = $this->shiftOf($employee, $data['shift_id']);

        if (! $employee->isActive()) {
            throw Errors::make(409, 'employee_terminated');
        }

        $planned = Errors::transaction(function () use ($employee, $shift, $data, $by) {
            [$start, $end] = $this->schedules->window($shift, $data['date'], $this->schedules->timezoneOf($employee));

            try {
                return RosterShift::create([
                    'employee_id' => $employee->id, 'company_id' => $employee->company_id, 'branch_id' => $employee->branch_id, 'location_id' => $employee->location_id,
                    'date' => $data['date'], 'shift_id' => $shift->id, 'start_at' => $start, 'end_at' => $end, 'break_minutes' => $shift->break_minutes,
                    'source' => 'manual', 'status' => RosterShift::PLANNED, 'created_by' => $by->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw Errors::make(422, 'shift_clash', field: 'date');
            }
        });

        $this->timesheets->touch($employee->id, $data['date']);

        return $planned->refresh();
    }

    /** @param array{date?: string, shift_id?: string} $data */
    public function update(RosterShift $planned, array $data): RosterShift
    {
        $employee = Employee::query()->findOrFail($planned->employee_id);
        $shift = $this->shiftOf($employee, $data['shift_id'] ?? $planned->shift_id);
        $oldDate = $planned->date->toDateString();

        $planned = Errors::transaction(function () use ($planned, $employee, $shift, $data) {
            $planned = RosterShift::query()->whereKey($planned->id)->lockForUpdate()->firstOrFail();

            if ($planned->status !== RosterShift::PLANNED) {
                throw Errors::make(409, 'document_not_open');
            }

            $date = $data['date'] ?? $planned->date->toDateString();
            [$start, $end] = $this->schedules->window($shift, $date, $this->schedules->timezoneOf($employee));

            try {
                $planned->fill(['date' => $date, 'shift_id' => $shift->id, 'start_at' => $start, 'end_at' => $end, 'break_minutes' => $shift->break_minutes, 'source' => 'manual'])->save();
            } catch (UniqueConstraintViolationException) {
                throw Errors::make(422, 'shift_clash', field: 'date');
            }

            return $planned->refresh();
        });

        $this->timesheets->touch($planned->employee_id, $oldDate);
        $this->timesheets->touch($planned->employee_id, $planned->date->toDateString());

        return $planned;
    }

    public function cancel(RosterShift $planned): RosterShift
    {
        $planned = Errors::transaction(function () use ($planned) {
            $planned = RosterShift::query()->whereKey($planned->id)->lockForUpdate()->firstOrFail();

            if ($planned->status !== RosterShift::PLANNED) {
                throw Errors::make(409, 'document_not_open');
            }

            $planned->fill(['status' => RosterShift::CANCELLED])->save();

            return $planned->refresh();
        });

        $this->timesheets->touch($planned->employee_id, $planned->date->toDateString());

        return $planned;
    }

    private function shiftOf(Employee $employee, string $shiftId): Shift
    {
        $shift = Shift::query()->whereKey($shiftId)->whereNull('archived_at')->first();

        if ($shift === null || $shift->company_id !== $employee->company_id) {
            throw Errors::make(422, 'shift_other_company', field: 'shift_id');
        }

        return $shift;
    }
}
