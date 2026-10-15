<?php

namespace Modules\HR\Http\Requests;

/** POST hr/attendance-devices/{hr_attendance_device}/token|suspend|resume|retire (HR-24): `hr.device.manage` at its location. */
class DeviceRequest extends SetupRowRequest
{
    protected function parameter(): string
    {
        return 'hr_attendance_device';
    }

    protected function viewPermission(): string
    {
        return 'hr.device.manage';
    }

    protected function managePermission(): string
    {
        return 'hr.device.manage';
    }
}
