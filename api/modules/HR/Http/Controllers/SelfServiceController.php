<?php

namespace Modules\HR\Http\Controllers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\HR\Attendance\Punches;
use Modules\HR\Employees\Employees;
use Modules\HR\Http\Lists\EmployeeList;
use Modules\HR\Http\Lists\LeaveRequestList;
use Modules\HR\Http\Requests\SelfAppraisalRequest;
use Modules\HR\Http\Requests\SelfClockRequest;
use Modules\HR\Http\Requests\SelfLeaveActionRequest;
use Modules\HR\Http\Requests\SelfLeaveRequest;
use Modules\HR\Http\Requests\SelfRangeRequest;
use Modules\HR\Http\Requests\SelfRequest;
use Modules\HR\Http\Requests\SelfSwapActionRequest;
use Modules\HR\Http\Requests\SelfSwapRequest;
use Modules\HR\Http\Requests\ShowSelfRequest;
use Modules\HR\Http\Requests\UpdateSelfRequest;
use Modules\HR\Http\Resources\AttendanceDayResource;
use Modules\HR\Http\Resources\EmployeeResource;
use Modules\HR\Http\Resources\LeaveRequestResource;
use Modules\HR\Http\Resources\PunchResource;
use Modules\HR\Http\Resources\RecordResource;
use Modules\HR\Http\Resources\Refs;
use Modules\HR\Http\Resources\RosterShiftResource;
use Modules\HR\Http\Resources\ShiftSwapResource;
use Modules\HR\Leave\LeaveLedger;
use Modules\HR\Leave\LeaveRequests;
use Modules\HR\Models\Appraisal;
use Modules\HR\Models\AttendanceDay;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Modules\HR\Models\Punch;
use Modules\HR\Models\RosterShift;
use Modules\HR\Models\ShiftSwap;
use Modules\HR\Records\Appraisals;
use Modules\HR\Rosters\Schedules;
use Modules\HR\Rosters\ShiftSwaps;
use Modules\HR\Support\Errors;

/** HR-31: employee self-service, for the signed-in user's own employee record (AUTH-12). */
class SelfServiceController
{
    /** HR-31: the user's own record; 204 when the user is not linked to an active employee (decision 30). */
    public function show(ShowSelfRequest $request): EmployeeResource|Response
    {
        $me = $request->linked();

        return $me === null ? response()->noContent() : EmployeeResource::make($me->load([...EmployeeList::RELATIONS, 'identifiers', 'payAccounts']))->self();
    }

    public function update(UpdateSelfRequest $request, Employees $employees): EmployeeResource
    {
        return EmployeeResource::make($employees->update($request->me(), $request->validated(), $request->user())->load([...EmployeeList::RELATIONS, 'identifiers', 'payAccounts']))->self();
    }

    public function leave(SelfRequest $request, LeaveLedger $ledger): JsonResponse
    {
        $me = $request->me();
        $today = CarbonImmutable::now();
        $types = LeaveType::query()->where('company_id', $me->company_id)->whereNull('archived_at')->orderBy('code')->get();

        return new JsonResponse(['data' => [
            'balances' => $types->map(fn (LeaveType $type) => ['leave_type' => Refs::coded($type), 'days_needed' => $type->needs_confirmation, 'tracked' => $type->accrual !== 'none', ...$ledger->summary($me->id, $type, $today)])->values()->all(),
            'requests' => LeaveRequestResource::collection(LeaveRequest::query()->with(LeaveRequestList::RELATIONS)->where('employee_id', $me->id)->orderByDesc('start_date')->orderByDesc('id')->limit(100)->get())->resolve($request),
        ]]);
    }

    public function requestLeave(SelfLeaveRequest $request, LeaveRequests $requests): JsonResponse
    {
        return LeaveRequestResource::make($requests->create($request->me(), $request->validated(), $request->user())->load(LeaveRequestList::RELATIONS))->response()->setStatusCode(201);
    }

    public function cancelLeave(SelfLeaveActionRequest $request, LeaveRequest $hrLeaveRequest, LeaveRequests $requests): LeaveRequestResource
    {
        return LeaveRequestResource::make($requests->cancel($hrLeaveRequest, $request->user(), $request->validated('reason'))->load(LeaveRequestList::RELATIONS));
    }

    public function roster(SelfRangeRequest $request): AnonymousResourceCollection
    {
        [$from, $to] = $this->range($request);

        return RosterShiftResource::collection(RosterShift::query()->with(['employee', 'shift'])->where('employee_id', $request->me()->id)
            ->where('status', RosterShift::PLANNED)->whereBetween('date', [$from, $to])->orderBy('date')->get());
    }

    public function attendance(SelfRangeRequest $request, Punches $punches, Schedules $schedules): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $me = $request->me();

        return new JsonResponse(['data' => [
            'days' => AttendanceDayResource::collection(AttendanceDay::query()->with('employee')->where('employee_id', $me->id)->whereBetween('work_date', [$from, $to])->orderBy('work_date')->get())->resolve($request),
            'punches' => PunchResource::collection(Punch::query()->with('employee')->where('employee_id', $me->id)->whereNull('voided_at')
                ->whereBetween('occurred_at', [CarbonImmutable::parse($from)->subDay(), CarbonImmutable::parse($to)->addDays(2)])->orderBy('occurred_at')->get())->resolve($request),
            'can_clock' => in_array('web', $me->clock_methods ?? [], true),
            // HR-22: what the clock button records next (the opposite of today's last punch).
            'next_kind' => $punches->nextKind($me, CarbonImmutable::now()->utc(), $schedules->timezoneOf($me)),
        ]]);
    }

    /**
     * HR-31, HR-19: colleagues the employee may ask for a swap (active
     * employees of the same company and branch) with their planned shifts
     * of the next 31 days; names, numbers and shift times only.
     */
    public function swapPeers(SelfRequest $request): JsonResponse
    {
        $me = $request->me();
        $peers = Employee::query()->where('company_id', $me->company_id)->where('status', 'active')->whereKeyNot($me->id)
            ->when($me->branch_id !== null, fn ($q) => $q->where('branch_id', $me->branch_id))
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')->limit(200)->get(['id', 'number', 'first_name', 'last_name']);
        $today = CarbonImmutable::now()->toDateString();
        $shifts = RosterShift::query()->with('shift')->whereIn('employee_id', $peers->pluck('id')->all())->where('status', RosterShift::PLANNED)
            ->whereBetween('date', [$today, CarbonImmutable::now()->addDays(31)->toDateString()])->orderBy('date')->get()->groupBy('employee_id');

        return new JsonResponse(['data' => $peers->map(fn ($peer) => [
            ...Refs::employee($peer),
            'shifts' => ($shifts[$peer->id] ?? collect())->map(fn (RosterShift $s) => [
                'id' => $s->id, 'date' => $s->date->toDateString(), 'shift' => Refs::coded($s->shift), 'start_at' => Refs::at($s->start_at), 'end_at' => Refs::at($s->end_at),
            ])->values()->all(),
        ])->values()->all()]);
    }

    public function clock(SelfClockRequest $request, Punches $punches, Schedules $schedules): JsonResponse
    {
        $me = $request->me();

        if (! in_array('web', $me->clock_methods ?? [], true)) {
            throw Errors::make(403, 'clock_method_off');
        }

        $now = CarbonImmutable::now()->utc();
        $kind = $request->validated('kind') ?? $punches->nextKind($me, $now, $schedules->timezoneOf($me));
        [$punch] = $punches->record($me, ['kind' => $kind, 'occurred_at' => $now, 'source' => 'web', 'recorded_by' => $request->user()->id]);

        return PunchResource::make($punch->load('employee'))->response()->setStatusCode(201);
    }

    public function swaps(SelfRequest $request): AnonymousResourceCollection
    {
        $me = $request->me();

        return ShiftSwapResource::collection(ShiftSwap::query()->with(RosterController::SWAP_RELATIONS)
            ->where(fn ($q) => $q->where('employee_id', $me->id)->orWhere('peer_employee_id', $me->id))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get());
    }

    public function requestSwap(SelfSwapRequest $request, ShiftSwaps $swaps): JsonResponse
    {
        return ShiftSwapResource::make($swaps->create($request->me(), $request->validated(), $request->user())->load(RosterController::SWAP_RELATIONS))->response()->setStatusCode(201);
    }

    public function acceptSwap(SelfSwapActionRequest $request, ShiftSwap $hrShiftSwap, ShiftSwaps $swaps): ShiftSwapResource
    {
        return ShiftSwapResource::make($swaps->accept($hrShiftSwap, $request->user())->load(RosterController::SWAP_RELATIONS));
    }

    public function declineSwap(SelfSwapActionRequest $request, ShiftSwap $hrShiftSwap, ShiftSwaps $swaps): ShiftSwapResource
    {
        return ShiftSwapResource::make($swaps->decline($hrShiftSwap, $request->user())->load(RosterController::SWAP_RELATIONS));
    }

    public function appraisals(SelfRequest $request): AnonymousResourceCollection
    {
        return RecordResource::collection(Appraisal::query()->with(['employee', 'cycle', 'reviewer'])->where('employee_id', $request->me()->id)->orderByDesc('created_at')->get());
    }

    public function updateAppraisal(SelfAppraisalRequest $request, Appraisal $hrAppraisal, Appraisals $appraisals): RecordResource
    {
        return RecordResource::make($appraisals->selfReview($hrAppraisal, $request->validated())->load(['employee', 'cycle', 'reviewer']));
    }

    public function acknowledgeAppraisal(SelfAppraisalRequest $request, Appraisal $hrAppraisal, Appraisals $appraisals): RecordResource
    {
        return RecordResource::make($appraisals->acknowledge($hrAppraisal)->load(['employee', 'cycle', 'reviewer']));
    }

    /** @return array{0: string, 1: string} */
    private function range(SelfRangeRequest $request): array
    {
        $from = CarbonImmutable::parse($request->validated('from', CarbonImmutable::now()->startOfWeek(CarbonInterface::MONDAY)->toDateString()));
        $to = CarbonImmutable::parse($request->validated('to', $from->addDays(6)->toDateString()));

        if ($to->lessThan($from) || $from->diffInDays($to) > 62) {
            throw Errors::make(422, 'range_too_long', ['days' => 62], 'to');
        }

        return [$from->toDateString(), $to->toDateString()];
    }
}
