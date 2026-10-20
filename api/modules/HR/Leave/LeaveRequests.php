<?php

namespace Modules\HR\Leave;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Modules\HR\Events\LeaveApproved;
use Modules\HR\Events\LeaveCancelled;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Modules\HR\Support\Errors;
use Modules\HR\Support\HrPack;
use Modules\HR\Support\Numbers;

/**
 * HR-14: leave requests.
 *
 * - create(): the employee's type (confirmed, HR-12), a range within the
 *   employment counting at least one day, no overlap with another pending
 *   or approved request, enough available days (types that accrue) or at
 *   most the type's days per request (types that do not); numbered and
 *   its `hr.leave_request` flow started in one transaction.
 * - completed(), cancelled(): the flow approved (days posted as taken,
 *   LeaveApproved), rejected or was cancelled.
 * - cancel(): pending (flow cancelled) or approved and not yet ended
 *   (days posted back, LeaveCancelled).
 */
class LeaveRequests
{
    public function __construct(
        private readonly LeaveDays $days,
        private readonly LeaveLedger $ledger,
        private readonly WorkflowEngine $engine,
        private readonly Numbers $numbers,
        private readonly TenantContext $tenants,
    ) {}

    /** @param array{leave_type_id: string, start_date: string, end_date: string, start_half?: bool, end_half?: bool, reason?: ?string} $data */
    public function create(Employee $employee, array $data, User $by): LeaveRequest
    {
        if (! $employee->isActive()) {
            throw Errors::make(409, 'employee_terminated');
        }

        $type = LeaveType::query()->whereKey($data['leave_type_id'])->whereNull('archived_at')->first();

        if ($type === null || $type->company_id !== $employee->company_id) {
            throw Errors::make(422, 'leave_type_other_company', field: 'leave_type_id');
        }

        if (! $type->usable()) {
            throw Errors::make(422, 'leave_days_needed', ['type' => $type->name], 'leave_type_id');
        }

        if ($data['start_date'] < $employee->hire_date->toDateString()) {
            throw Errors::make(422, 'leave_outside_employment', field: 'start_date');
        }

        // HR-12: some leave needs months of service first (KE: annual after 12, sick after 2).
        if ($type->qualifying_months !== null && $data['start_date'] < $employee->hire_date->addMonthsNoOverflow($type->qualifying_months)->toDateString()) {
            throw Errors::make(422, 'leave_qualifying_service', ['type' => $type->name, 'months' => $type->qualifying_months, 'date' => $employee->hire_date->addMonthsNoOverflow($type->qualifying_months)->toDateString()], 'start_date');
        }

        $event = $data['event'] ?? null;
        $eventDays = null;

        if ($event !== null) {
            $eventDays = collect($type->events ?? [])->firstWhere('key', $event)['days'] ?? null;

            if ($eventDays === null) {
                throw Errors::make(422, 'leave_event_unknown', ['type' => $type->name], 'event');
            }
        }

        $startHalf = (bool) ($data['start_half'] ?? false);
        $endHalf = (bool) ($data['end_half'] ?? false);
        $dates = $this->days->dates($type, $employee, $data['start_date'], $data['end_date'], $startHalf, $endHalf);
        $total = LeaveDays::total($dates);

        if (BigDecimal::of($total)->isZero()) {
            throw Errors::make(422, 'leave_no_days', field: 'start_date');
        }

        // HR-12: an event's days (CD circumstantial leave: own marriage 2, birth of a child 2).
        if ($eventDays !== null && BigDecimal::of($total)->isGreaterThan($eventDays)) {
            throw Errors::make(422, 'leave_event_days', ['event' => HrPack::label('leave_events', $event), 'days' => $eventDays], 'end_date');
        }

        return Errors::transaction(function () use ($employee, $type, $data, $by, $dates, $total, $startHalf, $endHalf, $event) {
            // One request at a time per employee: status, overlaps and balances are checked under this lock.
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw Errors::make(409, 'employee_terminated');
            }

            $overlap = LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', [LeaveRequest::PENDING, LeaveRequest::APPROVED])
                ->where('start_date', '<=', $data['end_date'])->where('end_date', '>=', $data['start_date'])->exists();

            if ($overlap) {
                throw Errors::make(422, 'leave_overlap', field: 'start_date');
            }

            $this->assertBalance($employee, $type, $data['end_date'], $total);
            $halfPay = $this->halfPayDays($employee, $type, $data['start_date'], $total);

            $request = LeaveRequest::create([
                'number' => $this->numbers->next(Numbers::LEAVE_REQUEST, $employee->company_id, $employee->branch_id),
                'employee_id' => $employee->id,
                'company_id' => $employee->company_id,
                'branch_id' => $employee->branch_id,
                'location_id' => $employee->location_id,
                'leave_type_id' => $type->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'start_half' => $startHalf,
                'end_half' => $endHalf,
                'days' => $total,
                'dates' => $dates,
                'reason' => $data['reason'] ?? null,
                'event' => $event,
                'half_pay_days' => $halfPay,
                // CP-01: the type's days come from public research: the request records it.
                'research' => $type->research,
                'status' => LeaveRequest::PENDING,
                'requested_by' => $by->id,
            ]);

            $this->engine->start(LeaveRequestType::KEY, $request->id, $by);

            return $request->refresh();
        });
    }

    /** WF-10: the flow ended. */
    public function completed(string $requestId, string $outcome, ?string $userId): void
    {
        Errors::transaction(function () use ($requestId, $outcome, $userId) {
            $request = LeaveRequest::query()->whereKey($requestId)->lockForUpdate()->first();

            if ($request === null || $request->status !== LeaveRequest::PENDING) {
                return;
            }

            $approved = $outcome === 'approved';
            $request->fill(['status' => $approved ? LeaveRequest::APPROVED : LeaveRequest::REJECTED, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();

            if (! $approved) {
                return;
            }

            $this->ledger->post($request->employee_id, $request->leave_type_id, 'taken', '-'.$request->days, $request->start_date->toDateString(), "request:{$request->id}", $request->id, null, $userId);
            $type = LeaveType::query()->findOrFail($request->leave_type_id);

            LeaveApproved::dispatch(
                $this->tenants->require(), $request->id, $request->number, $request->employee_id, $request->company_id, $type->id, $type->code, $type->paid,
                $request->start_date->toDateString(), $request->end_date->toDateString(), $request->start_half, $request->end_half, (string) $request->days, $request->dates,
            );
        });
    }

    /** WF-11: the request's flow was cancelled elsewhere. */
    public function cancelled(string $requestId): void
    {
        Errors::transaction(function () use ($requestId) {
            $request = LeaveRequest::query()->whereKey($requestId)->lockForUpdate()->first();

            if ($request !== null && $request->status === LeaveRequest::PENDING) {
                $request->fill(['status' => LeaveRequest::CANCELLED, 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function cancel(LeaveRequest $request, User $by, ?string $reason): LeaveRequest
    {
        return Errors::transaction(function () use ($request, $by, $reason) {
            $request = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $today = CarbonImmutable::now()->toDateString();

            if ($request->status === LeaveRequest::PENDING) {
                $workflow = $this->engine->current(LeaveRequestType::KEY, $request->id);

                if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                    $this->engine->cancel($workflow, null, (string) $reason);
                }
            } elseif ($request->status !== LeaveRequest::APPROVED || $request->end_date->toDateString() < $today) {
                throw Errors::make(409, 'document_not_open');
            }

            $wasApproved = $request->status === LeaveRequest::APPROVED;
            $request->fill(['status' => LeaveRequest::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            if ($wasApproved) {
                $this->ledger->post($request->employee_id, $request->leave_type_id, 'reversal', (string) $request->days, $request->start_date->toDateString(), "reversal:{$request->id}", $request->id, $reason, $by->id);
                LeaveCancelled::dispatch($this->tenants->require(), $request->id, $request->employee_id, $request->company_id, $request->leave_type_id, (string) $request->days, $request->dates);
            }

            return $request->refresh();
        });
    }

    /**
     * HR-05: on termination at $date, leave after it goes: requests that
     * start after the date are cancelled (approved ones whatever their end,
     * a backdated termination included) and requests spanning it are
     * trimmed to end on it (the days after it posted back). Requests wholly
     * before the date are left as they are.
     */
    public function cancelAfter(Employee $employee, CarbonImmutable $date, User $by): void
    {
        $day = $date->toDateString();
        $requests = LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', [LeaveRequest::PENDING, LeaveRequest::APPROVED])
            ->where('end_date', '>', $day)->orderBy('start_date')->get();

        foreach ($requests as $request) {
            Errors::transaction(function () use ($request, $day, $by) {
                $request = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                $kept = array_values(array_filter($request->dates, fn (array $d) => $d['date'] <= $day));

                if ($request->start_date->toDateString() > $day || BigDecimal::of(LeaveDays::total($kept))->isZero()) {
                    $this->withdraw($request, $by, __('hr.leave.cancelled_on_termination'));
                } else {
                    $this->trim($request, $day, $kept, $by);
                }
            });
        }
    }

    /** Cancel a pending or approved request whatever its dates (the days of an approved one posted back). */
    private function withdraw(LeaveRequest $request, User $by, string $reason): void
    {
        $wasApproved = $request->status === LeaveRequest::APPROVED;

        if (! $wasApproved) {
            $workflow = $this->engine->current(LeaveRequestType::KEY, $request->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, $reason);
            }
        }

        $request->fill(['status' => LeaveRequest::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

        if ($wasApproved) {
            $this->ledger->post($request->employee_id, $request->leave_type_id, 'reversal', (string) $request->days, $request->start_date->toDateString(), "reversal:{$request->id}", $request->id, $reason, $by->id);
            LeaveCancelled::dispatch($this->tenants->require(), $request->id, $request->employee_id, $request->company_id, $request->leave_type_id, (string) $request->days, $request->dates);
        }
    }

    /**
     * End a request on $day: the dates after it are dropped (posted back
     * when approved, with LeaveCancelled for them).
     *
     * @param  list<array{date: string, fraction: string}>  $kept
     */
    private function trim(LeaveRequest $request, string $day, array $kept, User $by): void
    {
        $removed = array_values(array_filter($request->dates, fn (array $d) => $d['date'] > $day));
        $removedDays = LeaveDays::total($removed);
        $request->fill(['end_date' => $day, 'end_half' => false, 'dates' => $kept, 'days' => LeaveDays::total($kept)])->save();

        if ($request->status === LeaveRequest::APPROVED && ! BigDecimal::of($removedDays)->isZero()) {
            $this->ledger->post($request->employee_id, $request->leave_type_id, 'reversal', $removedDays, $request->start_date->toDateString(), "trim:{$request->id}", $request->id, __('hr.leave.cancelled_on_termination'), $by->id);
            LeaveCancelled::dispatch($this->tenants->require(), $request->id, $request->employee_id, $request->company_id, $request->leave_type_id, $removedDays, $removed);
        }
    }

    /**
     * HR-12: the request's days at half pay: those beyond the type's
     * full-pay days in the calendar year, counting the year's pending and
     * approved requests of the type before it (KE sick leave: 30 full,
     * then 15 half). Payroll does not reduce pay for them yet.
     */
    private function halfPayDays(Employee $employee, LeaveType $type, string $startDate, string $days): string
    {
        if ($type->half_pay_days === null || BigDecimal::of((string) $type->half_pay_days)->isZero() || $type->entitlement_days === null) {
            return '0';
        }

        $year = CarbonImmutable::parse($startDate)->year;
        $used = LeaveRequest::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->whereIn('status', [LeaveRequest::PENDING, LeaveRequest::APPROVED])
            ->whereBetween('start_date', ["{$year}-01-01", "{$year}-12-31"])->sum('days');
        $fullLeft = BigDecimal::max(BigDecimal::of((string) $type->entitlement_days)->minus((string) $used), 0);

        return (string) BigDecimal::max(BigDecimal::of($days)->minus($fullLeft), 0)->toScale(2);
    }

    private function assertBalance(Employee $employee, LeaveType $type, string $endDate, string $days): void
    {
        if ($type->allow_negative) {
            return;
        }

        $needed = BigDecimal::of($days);

        if ($type->accrual === 'none') {
            // Not tracked as a balance: at most the type's days per request.
            if ($type->entitlement_days !== null && $needed->isGreaterThan(BigDecimal::of((string) $type->yearlyDays()))) {
                throw Errors::make(422, 'leave_balance_short', ['available' => (string) $type->yearlyDays()], 'end_date');
            }

            return;
        }

        $available = BigDecimal::of($this->ledger->available($employee->id, $type->id, CarbonImmutable::parse($endDate)));

        if ($needed->isGreaterThan($available)) {
            throw Errors::make(422, 'leave_balance_short', ['available' => (string) $available->toScale(2)], 'end_date');
        }
    }
}
