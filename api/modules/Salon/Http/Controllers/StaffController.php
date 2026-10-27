<?php

namespace Modules\Salon\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\HR\Contracts\EmployeeDirectory;
use Modules\Salon\Http\Requests\ActionRequest;
use Modules\Salon\Http\Requests\ArchiveRequest;
use Modules\Salon\Http\Requests\StaffHoursRequest;
use Modules\Salon\Http\Requests\StoreStaffRequest;
use Modules\Salon\Http\Requests\StoreTimeOffRequest;
use Modules\Salon\Http\Requests\UpdateStaffRequest;
use Modules\Salon\Http\Requests\ViewRequest;
use Modules\Salon\Http\Resources\Views;
use Modules\Salon\Models\Staff;
use Modules\Salon\Models\StaffHour;
use Modules\Salon\Models\TimeOff;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Hours;
use Modules\Salon\Support\Places;

/** SAL-03, SAL-04: staff, their weekly hours and their time off. */
class StaffController
{
    public function __construct(
        private readonly Places $places,
        private readonly Views $views,
        private readonly Hours $hours,
        private readonly ModuleRegistry $modules,
    ) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = Staff::query()->orderBy('name')->orderBy('id');
        $this->places->filterCompanies($query, $request->user(), $request->permission());
        $request->filled('company_id') && $query->where('company_id', $request->input('company_id'));
        $request->filled('branch_id') && $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $request->input('branch_id')));
        $request->boolean('archived') || $query->whereNull('archived_at');

        return response()->json(['data' => $this->views->staff($query->get())]);
    }

    public function show(ActionRequest $request, Staff $salonStaff): JsonResponse
    {
        return response()->json(['data' => $this->views->staff(collect([$salonStaff]))[0]]);
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->assertLinks($data['company_id'], $data);

        $staff = Errors::named(fn () => Staff::create($data));

        return response()->json(['data' => $this->views->staff(collect([$staff->refresh()]))[0]], 201);
    }

    public function update(UpdateStaffRequest $request, Staff $salonStaff): JsonResponse
    {
        $data = $request->validated();
        $this->assertLinks($salonStaff->company_id, $data, $salonStaff->id);

        // A new home branch must be one the editor reaches too (the request checked the old place).
        if (! empty($data['branch_id']) && $data['branch_id'] !== $salonStaff->branch_id && ! $this->places->at($request->user(), 'salon.staff.manage', Scope::branch($data['branch_id']))) {
            throw Errors::make(403, 'not_permitted', field: 'branch_id');
        }

        Errors::named(fn () => $salonStaff->fill($data)->save());

        return response()->json(['data' => $this->views->staff(collect([$salonStaff->refresh()]))[0]]);
    }

    public function archive(ArchiveRequest $request, Staff $salonStaff): JsonResponse
    {
        $salonStaff->isArchived() || $salonStaff->archive();

        return response()->json(['data' => $this->views->staff(collect([$salonStaff->refresh()]))[0]]);
    }

    public function hours(ActionRequest $request, Staff $salonStaff): JsonResponse
    {
        return $this->hoursOf($salonStaff);
    }

    private function hoursOf(Staff $salonStaff): JsonResponse
    {
        return response()->json(['data' => StaffHour::query()->where('staff_id', $salonStaff->id)->orderBy('branch_id')->orderBy('weekday')->orderBy('starts_at')->get()
            ->map(fn (StaffHour $h) => ['id' => $h->id, 'branch_id' => $h->branch_id, 'weekday' => $h->weekday, 'kind' => $h->kind, 'start' => substr((string) $h->starts_at, 0, 5), 'end' => substr((string) $h->ends_at, 0, 5)])
            ->values()->all()]);
    }

    public function setHours(StaffHoursRequest $request, Staff $salonStaff): JsonResponse
    {
        $this->hours->replace($salonStaff, $request->validated('hours'));

        return $this->hoursOf($salonStaff);
    }

    public function timeOff(ActionRequest $request, Staff $salonStaff): JsonResponse
    {
        return response()->json(['data' => $this->views->timeOff(TimeOff::query()->where('staff_id', $salonStaff->id)->whereNull('cancelled_at')->where('ends_at', '>', now()->subDays(30))->orderBy('starts_at')->get())]);
    }

    public function storeTimeOff(StoreTimeOffRequest $request, Staff $salonStaff): JsonResponse
    {
        $data = $request->validated();
        $entry = TimeOff::create([
            'staff_id' => $salonStaff->id, 'starts_at' => CarbonImmutable::parse($data['starts_at'])->utc(), 'ends_at' => CarbonImmutable::parse($data['ends_at'])->utc(),
            'reason' => $data['reason'], 'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->views->timeOff(collect([$entry->refresh()]))[0]], 201);
    }

    public function cancelTimeOff(ActionRequest $request, TimeOff $salonTimeoff): JsonResponse
    {
        $salonTimeoff->cancelled_at === null && $salonTimeoff->forceFill(['cancelled_at' => now()])->save();

        return response()->json(['data' => $this->views->timeOff(collect([$salonTimeoff->refresh()]))[0]]);
    }

    /** The user and the HR employee a staff member is, if named: read under the tenant, of the company, and once. */
    private function assertLinks(string $companyId, array $data, ?string $ownId = null): void
    {
        if (! empty($data['branch_id']) && $this->places->openBranch($data['branch_id'])->company_id !== $companyId) {
            throw Errors::make(422, 'branch_unknown', field: 'branch_id');
        }

        if (! empty($data['user_id'])) {
            $user = User::query()->find($data['user_id']);

            if ($user === null || ! $user->isActive()) {
                throw Errors::make(422, 'user_unknown', field: 'user_id');
            }

            if (Staff::query()->where('company_id', $companyId)->whereNull('archived_at')->where('user_id', $user->id)->when($ownId !== null, fn ($q) => $q->whereKeyNot($ownId))->exists()) {
                throw Errors::make(422, 'user_taken', field: 'user_id');
            }
        }

        if (! empty($data['employee_id'])) {
            $found = $this->modules->isActive('hr') ? app(EmployeeDirectory::class)->find($data['employee_id']) : null;

            if ($found === null || $found['company_id'] !== $companyId) {
                throw Errors::make(422, 'employee_unknown', field: 'employee_id');
            }

            if (Staff::query()->where('company_id', $companyId)->whereNull('archived_at')->where('employee_id', $data['employee_id'])->when($ownId !== null, fn ($q) => $q->whereKeyNot($ownId))->exists()) {
                throw Errors::make(422, 'employee_taken', field: 'employee_id');
            }
        }
    }
}
