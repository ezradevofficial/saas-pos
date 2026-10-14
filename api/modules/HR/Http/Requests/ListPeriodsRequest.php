<?php

namespace Modules\HR\Http\Requests;

/** GET hr/attendance-periods (HR-26): rows of the companies the reader reads with `hr.attendance.view`. */
class ListPeriodsRequest extends SetupListRequest
{
    protected function viewPermission(): string
    {
        return 'hr.attendance.view';
    }

    protected function archivable(): bool
    {
        return false;
    }
}
