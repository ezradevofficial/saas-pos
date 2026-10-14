<?php

namespace Modules\HR\Http\Requests;

/** GET hr/attendance-devices (HR-24): rows of the companies the reader reads with `hr.device.manage`. */
class ListDevicesRequest extends SetupListRequest
{
    protected function viewPermission(): string
    {
        return 'hr.device.manage';
    }

    protected function archivable(): bool
    {
        return false;
    }
}
