<?php

namespace Modules\HR\Http\Requests;

/** GET hr/shifts (HR-16): rows of the companies the reader reads with `hr.roster.view`. */
class ListShiftsRequest extends SetupListRequest
{
    protected function viewPermission(): string
    {
        return 'hr.roster.view';
    }

    protected function archivable(): bool
    {
        return true;
    }
}
