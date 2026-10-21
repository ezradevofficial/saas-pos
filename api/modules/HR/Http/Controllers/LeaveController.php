<?php

namespace Modules\HR\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Exports\ListExport;
use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HR\Http\Lists\LeaveRequestList;
use Modules\HR\Http\Requests\ApplyPackRequest;
use Modules\HR\Http\Requests\LeaveAdjustmentRequest;
use Modules\HR\Http\Requests\LeaveBalancesRequest;
use Modules\HR\Http\Requests\LeaveCalendarRequest;
use Modules\HR\Http\Requests\LeaveRequestRequest;
use Modules\HR\Http\Requests\LeaveTypeRequest;
use Modules\HR\Http\Requests\ListLeaveRequestsRequest;
use Modules\HR\Http\Requests\ListLeaveTypesRequest;
use Modules\HR\Http\Requests\RunAccrualsRequest;
use Modules\HR\Http\Requests\StoreLeaveRequestRequest;
use Modules\HR\Http\Requests\StoreLeaveTypeRequest;
use Modules\HR\Http\Resources\LeaveRequestResource;
use Modules\HR\Http\Resources\LeaveTypeResource;
use Modules\HR\Http\Resources\Refs;
use Modules\HR\Leave\Accruals;
use Modules\HR\Leave\LeaveLedger;
use Modules\HR\Leave\LeaveRequests;
use Modules\HR\Leave\LeaveTypes;
use Modules\HR\Models\Employee;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Modules\HR\Support\Access;
use Modules\HR\Support\Errors;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HR-12 to HR-15: leave types, balances, adjustments, accruals, requests and the calendar. */
class LeaveController
{
    public function __construct(
        private readonly LeaveTypes $types,
        private readonly LeaveRequests $requests,
        private readonly LeaveLedger $ledger,
        private readonly Access $access,
    ) {}

    public function types(ListLeaveTypesRequest $request): AnonymousResourceCollection
    {
        $query = $request->applyFilters(LeaveType::query(), ['code', 'name'])->orderBy('code')->orderBy('id');

        return LeaveTypeResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function storeType(StoreLeaveTypeRequest $request): JsonResponse
    {
        return LeaveTypeResource::make($this->types->create($request->validated())->refresh())->response()->setStatusCode(201);
    }

    public function updateType(LeaveTypeRequest $request, LeaveType $hrLeaveType): LeaveTypeResource
    {
        return LeaveTypeResource::make($this->types->update($hrLeaveType, $request->validated()));
    }

    public function archiveType(LeaveTypeRequest $request, LeaveType $hrLeaveType): LeaveTypeResource
    {
        if (! $hrLeaveType->isArchived()) {
            $hrLeaveType->archive();
        }

        return LeaveTypeResource::make($hrLeaveType->refresh());
    }

    public function applyPack(ApplyPackRequest $request): AnonymousResourceCollection
    {
        return LeaveTypeResource::collection($this->types->applyPack(Company::query()->findOrFail($request->validated('company_id'))));
    }

    public function balances(LeaveBalancesRequest $request): JsonResponse
    {
        // HR-15: without `as_of`, each employee's balance is as of today at their place (branch, else company), not UTC's day.
        $given = $request->validated('as_of');
        $days = [];
        $asOfFor = function (Employee $employee) use ($given, &$days): CarbonImmutable {
            return CarbonImmutable::parse($given ?? ($days[$employee->company_id.'|'.$employee->branch_id] ??= CompanyClock::today($employee->company_id, $employee->branch_id)));
        };
        $query = Employee::query()->where('status', Employee::ACTIVE);
        $this->access->limit($query, $request->user(), 'hr.leave.view');

        foreach (['company' => 'company_id', 'branch' => 'branch_id', 'location' => 'location_id', 'department' => 'department_id', 'employee' => 'id'] as $filter => $column) {
            if ($request->filled($filter)) {
                $query->where($column, $request->validated($filter));
            }
        }

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($q) => $q->where('number', 'ilike', $like)->orWhere('first_name', 'ilike', $like)->orWhere('last_name', 'ilike', $like));
        }

        $page = $query->orderBy('number')->orderBy('id')->paginate($request->perPage())->withQueryString();
        $types = LeaveType::query()->whereIn('company_id', $page->getCollection()->pluck('company_id')->unique()->all())->whereNull('archived_at')->orderBy('code')->get()->groupBy('company_id');

        $rows = $page->getCollection()->map(fn (Employee $employee) => [
            'employee' => Refs::employee($employee),
            'company_id' => $employee->company_id,
            'balances' => ($types[$employee->company_id] ?? collect())->map(fn (LeaveType $type) => [
                'leave_type' => Refs::coded($type),
                'days_needed' => $type->needs_confirmation,
                'tracked' => $type->accrual !== 'none',
                ...$this->ledger->summary($employee->id, $type, $asOfFor($employee)),
            ])->values()->all(),
        ])->values()->all();
        $asOf = $given ?? ($request->filled('company') ? CompanyClock::today($request->validated('company'), $request->validated('branch')) : null)
            ?? ($page->getCollection()->isEmpty() ? CompanyClock::today(null) : $asOfFor($page->getCollection()->first())->toDateString());

        return new JsonResponse(['data' => $rows, 'meta' => ['as_of' => $asOf, 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function adjust(LeaveAdjustmentRequest $request): JsonResponse
    {
        $employee = $request->bodyEmployee();
        $type = LeaveType::query()->findOrFail($request->validated('leave_type_id'));

        if ($type->company_id !== $employee->company_id) {
            throw Errors::make(422, 'leave_type_other_company', field: 'leave_type_id');
        }

        // HR-15: an adjustment takes effect today at the employee's place by default, not on UTC's day.
        $today = CompanyClock::today($employee->company_id, $employee->branch_id);
        $entry = Errors::transaction(function () use ($request, $employee, $type, $today) {
            $entry = $this->ledger->post($employee->id, $type->id, 'adjustment', (string) $request->validated('days'), $request->validated('effective_on', $today),
                'adjustment:'.str()->uuid7(), null, (string) $request->validated('reason'), $request->user()->id);
            app(Auditor::class)->record('hr.leave.adjust', $employee, null, ['leave_type_id' => $type->id, 'days' => (string) $request->validated('days'), 'reason' => $request->validated('reason')]);

            return $entry;
        });

        return new JsonResponse(['data' => [
            'id' => $entry->id, 'days' => (string) $entry->days, 'effective_on' => $entry->effective_on->toDateString(),
            ...$this->ledger->summary($employee->id, $type, CarbonImmutable::parse($today)),
        ]], 201);
    }

    public function runAccruals(RunAccrualsRequest $request, Accruals $accruals): JsonResponse
    {
        $asOf = CarbonImmutable::parse($request->validated('as_of', CompanyClock::today((string) $request->validated('company_id'))));

        return new JsonResponse(['data' => $accruals->run($asOf, (string) $request->validated('company_id'))]);
    }

    public function index(ListLeaveRequestsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = LeaveRequest::query()->with(LeaveRequestList::RELATIONS);
        $request->applyFilters($query, dateColumn: 'start_date');
        $request->applySearch($query, ['number' => 'number', 'reason' => 'reason']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return LeaveRequestResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        $leave = $this->requests->create($request->bodyEmployee(), $request->safe()->except('employee_id'), $request->user());

        return LeaveRequestResource::make($leave->load(LeaveRequestList::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(LeaveRequestRequest $request, LeaveRequest $hrLeaveRequest): LeaveRequestResource
    {
        return LeaveRequestResource::make($hrLeaveRequest->load(LeaveRequestList::RELATIONS));
    }

    public function cancel(LeaveRequestRequest $request, LeaveRequest $hrLeaveRequest): LeaveRequestResource
    {
        return LeaveRequestResource::make($this->requests->cancel($hrLeaveRequest, $request->user(), $request->validated('reason'))->load(LeaveRequestList::RELATIONS));
    }

    public function calendar(LeaveCalendarRequest $request): JsonResponse
    {
        $zone = CompanyClock::timezone($request->validated('company'), $request->validated('branch'));
        $from = CarbonImmutable::parse($request->validated('from', CarbonImmutable::now($zone)->startOfMonth()->toDateString()));
        $to = CarbonImmutable::parse($request->validated('to', $from->endOfMonth()->toDateString()));

        if ($to->lessThan($from) || $from->diffInDays($to) > 92) {
            throw Errors::make(422, 'range_too_long', ['days' => 92], 'to');
        }

        $query = LeaveRequest::query()->with(['employee', 'leaveType'])->whereIn('status', [LeaveRequest::PENDING, LeaveRequest::APPROVED])
            ->where('start_date', '<=', $to->toDateString())->where('end_date', '>=', $from->toDateString());
        $this->access->limit($query, $request->user(), 'hr.leave.view');

        foreach (['company' => 'company_id', 'branch' => 'branch_id', 'employee' => 'employee_id'] as $filter => $column) {
            if ($request->filled($filter)) {
                $query->where($column, $request->validated($filter));
            }
        }

        if ($request->filled('department')) {
            $query->whereIn('employee_id', fn ($q) => $q->select('id')->from('hr_employees')->where('department_id', $request->validated('department')));
        }

        return new JsonResponse(['data' => LeaveRequestResource::collection($query->orderBy('start_date')->orderBy('id')->get())->resolve($request), 'meta' => ['from' => $from->toDateString(), 'to' => $to->toDateString()]]);
    }
}
