<?php

namespace Modules\HR\Http\Middleware;

use App\Core\Audit\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Modules\HR\Models\AttendanceDevice;
use Symfony\Component\HttpFoundation\Response;

/** HR-24: device push routes accept only an active attendance device's token (ability `hr.attendance_device`). */
class EnsureAttendanceDeviceToken
{
    public function __construct(private readonly AuditContext $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();

        abort_unless($device instanceof AttendanceDevice && $device->acceptsTokens() && $device->tokenCan(AttendanceDevice::TOKEN_ABILITY), 403);

        $this->audit->setLocationId($device->location_id);

        return $next($request);
    }
}
