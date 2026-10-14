<?php

namespace Modules\HR\Rosters;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Modules\HR\Attendance\Timesheets;
use Modules\HR\Models\Employee;
use Modules\HR\Models\RosterShift;
use Modules\HR\Models\ShiftSwap;
use Modules\HR\Support\Errors;

/**
 * HR-19: shift swaps and give-aways. Asked for one of the employee's own
 * planned future shifts, with a peer of the same company (and one of the
 * peer's planned future shifts for a swap); the peer accepts, which starts
 * the `hr.shift_swap` flow; approval moves the shifts (cancelled and
 * planned again with `source = swap`) when they are still as they were.
 */
class ShiftSwaps
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly Schedules $schedules,
        private readonly Timesheets $timesheets,
    ) {}

    /** @param array{roster_shift_id: string, peer_employee_id: string, peer_roster_shift_id?: ?string, reason?: ?string} $data */
    public function create(Employee $employee, array $data, User $by): ShiftSwap
    {
        $shift = RosterShift::query()->whereKey($data['roster_shift_id'])->first();
        $peer = Employee::query()->whereKey($data['peer_employee_id'])->first();

        if ($shift === null || $shift->employee_id !== $employee->id || $shift->status !== RosterShift::PLANNED || $shift->start_at->isPast()) {
            throw Errors::make(422, 'swap_shift_invalid', field: 'roster_shift_id');
        }

        if ($peer === null || $peer->id === $employee->id || $peer->company_id !== $employee->company_id || ! $peer->isActive()) {
            throw Errors::make(422, 'employee_other_company', field: 'peer_employee_id');
        }

        $peerShift = null;

        if (($data['peer_roster_shift_id'] ?? null) !== null) {
            $peerShift = RosterShift::query()->whereKey($data['peer_roster_shift_id'])->first();

            if ($peerShift === null || $peerShift->employee_id !== $peer->id || $peerShift->status !== RosterShift::PLANNED || $peerShift->start_at->isPast()) {
                throw Errors::make(422, 'swap_shift_invalid', field: 'peer_roster_shift_id');
            }
        }

        $this->assertNoClash($employee, $shift, $peer, $peerShift);

        return Errors::transaction(fn () => ShiftSwap::create([
            'company_id' => $employee->company_id, 'branch_id' => $employee->branch_id, 'location_id' => $employee->location_id,
            'employee_id' => $employee->id, 'roster_shift_id' => $shift->id, 'peer_employee_id' => $peer->id, 'peer_roster_shift_id' => $peerShift?->id,
            'reason' => $data['reason'] ?? null, 'status' => ShiftSwap::AWAITING_PEER, 'requested_by' => $by->id,
        ]))->refresh();
    }

    /** The peer (or HR for them) accepts: the swap goes to its flow. */
    public function accept(ShiftSwap $swap, User $by): ShiftSwap
    {
        return Errors::transaction(function () use ($swap, $by) {
            $swap = ShiftSwap::query()->whereKey($swap->id)->lockForUpdate()->firstOrFail();

            if ($swap->status !== ShiftSwap::AWAITING_PEER) {
                throw Errors::make(409, 'document_not_open');
            }

            $swap->fill(['status' => ShiftSwap::PENDING, 'answered_by' => $by->id, 'answered_at' => CarbonImmutable::now()])->save();
            $this->engine->start(ShiftSwapType::KEY, $swap->id, User::query()->find($swap->requested_by) ?? $by);

            return $swap->refresh();
        });
    }

    public function decline(ShiftSwap $swap, User $by): ShiftSwap
    {
        return Errors::transaction(function () use ($swap, $by) {
            $swap = ShiftSwap::query()->whereKey($swap->id)->lockForUpdate()->firstOrFail();

            if ($swap->status !== ShiftSwap::AWAITING_PEER) {
                throw Errors::make(409, 'document_not_open');
            }

            $swap->fill(['status' => ShiftSwap::DECLINED, 'answered_by' => $by->id, 'answered_at' => CarbonImmutable::now()])->save();

            return $swap->refresh();
        });
    }

    public function cancel(ShiftSwap $swap): ShiftSwap
    {
        return Errors::transaction(function () use ($swap) {
            $swap = ShiftSwap::query()->whereKey($swap->id)->lockForUpdate()->firstOrFail();

            if (! in_array($swap->status, [ShiftSwap::AWAITING_PEER, ShiftSwap::PENDING], true)) {
                throw Errors::make(409, 'document_not_open');
            }

            $workflow = $this->engine->current(ShiftSwapType::KEY, $swap->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, __('hr.swaps.cancelled'));
            }

            $swap->fill(['status' => ShiftSwap::CANCELLED])->save();

            return $swap->refresh();
        });
    }

    /** WF-10: the flow ended; an approved swap moves the shifts if they are unchanged (else it is cancelled as stale). */
    public function completed(string $swapId, string $outcome, ?string $userId): void
    {
        $touched = Errors::transaction(function () use ($swapId, $outcome, $userId) {
            $swap = ShiftSwap::query()->whereKey($swapId)->lockForUpdate()->first();

            if ($swap === null || $swap->status !== ShiftSwap::PENDING) {
                return [];
            }

            if ($outcome !== 'approved') {
                $swap->fill(['status' => ShiftSwap::REJECTED, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();

                return [];
            }

            $shift = RosterShift::query()->whereKey($swap->roster_shift_id)->lockForUpdate()->first();
            $peerShift = $swap->peer_roster_shift_id === null ? null : RosterShift::query()->whereKey($swap->peer_roster_shift_id)->lockForUpdate()->first();
            $employee = Employee::query()->findOrFail($swap->employee_id);
            $peer = Employee::query()->findOrFail($swap->peer_employee_id);
            $stale = $shift->status !== RosterShift::PLANNED || $shift->employee_id !== $employee->id
                || ($peerShift !== null && ($peerShift->status !== RosterShift::PLANNED || $peerShift->employee_id !== $peer->id));

            try {
                if (! $stale) {
                    $this->assertNoClash($employee, $shift, $peer, $peerShift);
                }
            } catch (ApiException) {
                $stale = true;
            }

            if ($stale) {
                $swap->fill(['status' => ShiftSwap::CANCELLED, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();
                app(Auditor::class)->record('hr.shift_swap.stale', $swap, null, ['reason' => 'swap_stale']);

                return [];
            }

            $shift->fill(['status' => RosterShift::CANCELLED])->save();
            $peerShift?->fill(['status' => RosterShift::CANCELLED])->save();
            $this->replan($shift, $peer, $userId);

            if ($peerShift !== null) {
                $this->replan($peerShift, $employee, $userId);
            }

            $swap->fill(['status' => ShiftSwap::APPROVED, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();

            return array_filter([[$employee->id, $shift->date->toDateString()], [$peer->id, $shift->date->toDateString()],
                $peerShift === null ? null : [$peer->id, $peerShift->date->toDateString()], $peerShift === null ? null : [$employee->id, $peerShift->date->toDateString()]]);
        });

        foreach ($touched as [$employeeId, $date]) {
            $this->timesheets->touch($employeeId, $date);
        }
    }

    public function cancelled(string $swapId): void
    {
        Errors::transaction(function () use ($swapId) {
            $swap = ShiftSwap::query()->whereKey($swapId)->lockForUpdate()->first();

            if ($swap !== null && $swap->status === ShiftSwap::PENDING) {
                $swap->fill(['status' => ShiftSwap::CANCELLED])->save();
            }
        });
    }

    private function replan(RosterShift $from, Employee $to, ?string $userId): void
    {
        RosterShift::create([
            'employee_id' => $to->id, 'company_id' => $to->company_id, 'branch_id' => $to->branch_id, 'location_id' => $to->location_id,
            'date' => $from->date->toDateString(), 'shift_id' => $from->shift_id, 'start_at' => $from->start_at, 'end_at' => $from->end_at,
            'break_minutes' => $from->break_minutes, 'source' => 'swap', 'roster_id' => $from->roster_id, 'status' => RosterShift::PLANNED, 'created_by' => $userId,
        ]);
    }

    /** No one ends up with two planned shifts on one date. */
    private function assertNoClash(Employee $employee, RosterShift $shift, Employee $peer, ?RosterShift $peerShift): void
    {
        $peerHas = RosterShift::query()->where('employee_id', $peer->id)->where('status', RosterShift::PLANNED)->where('date', $shift->date->toDateString())
            ->when($peerShift !== null, fn ($q) => $q->whereKeyNot($peerShift->id))->exists();
        $employeeHas = $peerShift !== null && RosterShift::query()->where('employee_id', $employee->id)->where('status', RosterShift::PLANNED)
            ->where('date', $peerShift->date->toDateString())->whereKeyNot($shift->id)->exists();

        if ($peerHas || $employeeHas) {
            throw Errors::make(422, 'shift_clash', field: 'peer_employee_id');
        }
    }
}
