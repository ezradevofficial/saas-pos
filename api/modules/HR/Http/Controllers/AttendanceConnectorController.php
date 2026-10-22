<?php

namespace Modules\HR\Http\Controllers;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HR\Attendance\PunchUploads;
use Modules\HR\Http\Requests\ConnectorDevicesRequest;
use Modules\HR\Http\Requests\ConnectorPunchesRequest;
use Modules\HR\Http\Requests\ConnectorRosterRequest;
use Modules\HR\Http\Resources\ConnectorDeviceResource;
use Modules\HR\Http\Resources\ConnectorEmployeeResource;
use Modules\HR\Models\AttendanceDevice;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Access;
use Modules\HR\Support\Errors;

/**
 * HR-24, MKT-04 (ADR 024): the attendance device API on the public API,
 * for the biometric connector (an app principal) or an API key, as well as
 * people:
 *
 * - the active roster (`hr.employee.view` at each employee's place): id,
 *   display name and enrolment reference only, so a terminal can enrol and
 *   match people; no sensitive group (HR-09) is ever part of it, and the
 *   `hr_employee` field rules (RBAC-05) still hide what they name;
 * - the attendance devices (`hr.device.view` at each device's location);
 * - a device's punches (`hr.attendance.manage` at its location), through
 *   PunchUploads::fromDevice, the device's own path: matched by the
 *   device's event id (a resend answers what it answered first), within
 *   the offline window, by enrolment reference to an active employee of
 *   the device's company. Only an active device takes punches.
 */
class AttendanceConnectorController
{
    public function __construct(private readonly Access $access, private readonly ScopeResolver $resolver) {}

    public function roster(ConnectorRosterRequest $request): AnonymousResourceCollection
    {
        abort_unless($this->access->anywhere($request->user(), 'hr.employee.view'), 403);
        $query = $this->access->limit(Employee::query(), $request->user(), 'hr.employee.view')
            ->where('status', Employee::ACTIVE)
            ->when($request->filled('company'), fn ($q) => $q->where('company_id', $request->validated('company')))
            ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->validated('location')))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id');

        return ConnectorEmployeeResource::collection($query->paginate((int) $request->validated('per_page', 50))->withQueryString());
    }

    public function devices(ConnectorDevicesRequest $request): AnonymousResourceCollection
    {
        abort_unless($this->access->anywhere($request->user(), 'hr.device.view'), 403);
        $status = $request->validated('status', AttendanceDevice::ACTIVE);
        $query = $this->resolver->visibleIds($request->user(), 'hr.device.view')->applyTo(AttendanceDevice::query(), Scope::LOCATION, 'location_id')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('name')->orderBy('id');

        return ConnectorDeviceResource::collection($query->with('location')->paginate((int) $request->validated('per_page', 50))->withQueryString());
    }

    public function punches(ConnectorPunchesRequest $request, AttendanceDevice $hrAttendanceDevice, PunchUploads $uploads): JsonResponse
    {
        $user = $request->user();
        $at = Scope::location($hrAttendanceDevice->location_id);
        abort_unless($this->resolver->can($user, 'hr.device.view', $at) || $this->resolver->can($user, 'hr.attendance.manage', $at), 404);
        abort_unless($this->resolver->can($user, 'hr.attendance.manage', $at), 403);

        if (! $hrAttendanceDevice->acceptsTokens()) {
            throw Errors::make(409, 'device_not_active');
        }

        return new JsonResponse(['data' => $uploads->fromDevice($hrAttendanceDevice, $request->validated('punches'))]);
    }
}
