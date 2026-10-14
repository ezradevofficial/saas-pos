<?php

namespace Modules\HR\Http\Controllers;

use App\Core\Exports\ListExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HR\Attendance\AttendanceDevices;
use Modules\HR\Attendance\Periods;
use Modules\HR\Attendance\Punches;
use Modules\HR\Attendance\PunchUploads;
use Modules\HR\Attendance\TimeRules;
use Modules\HR\Attendance\Timesheets;
use Modules\HR\Http\Requests\DevicePunchesRequest;
use Modules\HR\Http\Requests\DeviceRequest;
use Modules\HR\Http\Requests\ListDevicesRequest;
use Modules\HR\Http\Requests\ListPeriodsRequest;
use Modules\HR\Http\Requests\ListPunchesRequest;
use Modules\HR\Http\Requests\ListTimeRulesRequest;
use Modules\HR\Http\Requests\ListTimesheetsRequest;
use Modules\HR\Http\Requests\PeriodRequest;
use Modules\HR\Http\Requests\RecomputeTimesheetsRequest;
use Modules\HR\Http\Requests\StoreDeviceRequest;
use Modules\HR\Http\Requests\StorePeriodRequest;
use Modules\HR\Http\Requests\StorePunchRequest;
use Modules\HR\Http\Requests\StoreTimeRuleRequest;
use Modules\HR\Http\Requests\TillPunchesRequest;
use Modules\HR\Http\Requests\TimeRuleRequest;
use Modules\HR\Http\Requests\TimesheetDaysRequest;
use Modules\HR\Http\Requests\VoidPunchRequest;
use Modules\HR\Http\Resources\AttendanceDayResource;
use Modules\HR\Http\Resources\PunchResource;
use Modules\HR\Http\Resources\SetupResource;
use Modules\HR\Models\AttendanceDay;
use Modules\HR\Models\AttendanceDevice;
use Modules\HR\Models\AttendancePeriod;
use Modules\HR\Models\Punch;
use Modules\HR\Models\TimeRule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HR-20 to HR-26: time rules, punches, timesheets, periods, devices and the device uploads. */
class AttendanceController
{
    public function __construct(
        private readonly Punches $punches,
        private readonly Timesheets $timesheets,
    ) {}

    public function timeRules(ListTimeRulesRequest $request): AnonymousResourceCollection
    {
        return SetupResource::collection($request->applyFilters(TimeRule::query())->orderByDesc('effective_from')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function storeTimeRule(StoreTimeRuleRequest $request, TimeRules $rules): JsonResponse
    {
        return SetupResource::make($rules->create($request->validated())->refresh())->response()->setStatusCode(201);
    }

    public function updateTimeRule(TimeRuleRequest $request, TimeRule $hrTimeRule, TimeRules $rules): SetupResource
    {
        return SetupResource::make($rules->update($hrTimeRule, $request->validated()));
    }

    public function punches(ListPunchesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Punch::query()->with('employee');
        $request->applyFilters($query, viaEmployee: true);

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($request->validated('from'))->subDay());
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<', CarbonImmutable::parse($request->validated('to'))->addDays(2));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return PunchResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function storePunch(StorePunchRequest $request): JsonResponse
    {
        [$punch] = $this->punches->record($request->bodyEmployee(), [
            'kind' => $request->validated('kind'), 'occurred_at' => CarbonImmutable::parse($request->validated('occurred_at'))->utc(),
            'source' => 'manual', 'note' => $request->validated('note'), 'recorded_by' => $request->user()->id,
        ]);

        return PunchResource::make($punch->load('employee'))->response()->setStatusCode(201);
    }

    public function voidPunch(VoidPunchRequest $request, Punch $hrPunch): PunchResource
    {
        return PunchResource::make($this->punches->void($hrPunch, $request->user(), (string) $request->validated('reason'))->load('employee'));
    }

    public function timesheets(ListTimesheetsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = AttendanceDay::query()->with('employee');
        $request->applyFilters($query, dateColumn: 'work_date');
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return AttendanceDayResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function approveDays(TimesheetDaysRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => ['approved' => $this->timesheets->approve($request->days(), $request->user())]]);
    }

    public function reopenDays(TimesheetDaysRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => ['reopened' => $this->timesheets->reopen($request->days(), $request->user())]]);
    }

    public function recompute(RecomputeTimesheetsRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => ['days' => $this->timesheets->recomputeRange((string) $request->validated('company_id'), (string) $request->validated('from'), (string) $request->validated('to'))]]);
    }

    public function periods(ListPeriodsRequest $request): AnonymousResourceCollection
    {
        return SetupResource::collection($request->applyFilters(AttendancePeriod::query())->orderByDesc('start_date')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function storePeriod(StorePeriodRequest $request, Periods $periods): JsonResponse
    {
        return SetupResource::make($periods->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function showPeriod(PeriodRequest $request, AttendancePeriod $hrAttendancePeriod): SetupResource
    {
        return SetupResource::make($hrAttendancePeriod);
    }

    public function closePeriod(PeriodRequest $request, AttendancePeriod $hrAttendancePeriod, Periods $periods): SetupResource
    {
        return SetupResource::make($periods->close($hrAttendancePeriod, $request->user()));
    }

    public function devices(ListDevicesRequest $request): AnonymousResourceCollection
    {
        return SetupResource::collection($request->applyFilters(AttendanceDevice::query(), ['name'])->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    public function storeDevice(StoreDeviceRequest $request, AttendanceDevices $devices): JsonResponse
    {
        ['device' => $device, 'token' => $token] = $devices->create($request->validated());

        return SetupResource::make($device)->additional(['meta' => ['token' => $token]])->response()->setStatusCode(201);
    }

    public function rotateToken(DeviceRequest $request, AttendanceDevice $hrAttendanceDevice, AttendanceDevices $devices): JsonResponse
    {
        return new JsonResponse(['data' => ['token' => $devices->rotate($hrAttendanceDevice)]]);
    }

    public function suspendDevice(DeviceRequest $request, AttendanceDevice $hrAttendanceDevice, AttendanceDevices $devices): SetupResource
    {
        return SetupResource::make($devices->status($hrAttendanceDevice, AttendanceDevice::SUSPENDED));
    }

    public function resumeDevice(DeviceRequest $request, AttendanceDevice $hrAttendanceDevice, AttendanceDevices $devices): SetupResource
    {
        return SetupResource::make($devices->status($hrAttendanceDevice, AttendanceDevice::ACTIVE));
    }

    public function retireDevice(DeviceRequest $request, AttendanceDevice $hrAttendanceDevice, AttendanceDevices $devices): SetupResource
    {
        return SetupResource::make($devices->status($hrAttendanceDevice, AttendanceDevice::RETIRED));
    }

    /** HR-23: a till's queued clock-ins. */
    public function tillPunches(TillPunchesRequest $request, PunchUploads $uploads): JsonResponse
    {
        return new JsonResponse(['data' => $uploads->fromTill($request->user(), $request->validated('punches'))]);
    }

    /** HR-24: a biometric device's punches. */
    public function devicePunches(DevicePunchesRequest $request, PunchUploads $uploads): JsonResponse
    {
        return new JsonResponse(['data' => $uploads->fromDevice($request->user(), $request->validated('punches'))]);
    }
}
