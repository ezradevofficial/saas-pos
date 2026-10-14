<?php

namespace Modules\HR\Http\Requests;

/** GET hr/leave-types (HR-12): rows of the companies the reader reads with `hr.leave.view`. */
class ListLeaveTypesRequest extends SetupListRequest
{
    protected function viewPermission(): string
    {
        return 'hr.leave.view';
    }

    protected function archivable(): bool
    {
        return true;
    }
}
