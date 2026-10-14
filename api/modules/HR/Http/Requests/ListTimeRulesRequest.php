<?php

namespace Modules\HR\Http\Requests;

/** GET hr/time-rules (HR-20): rows of the companies the reader reads with `hr.attendance.view`. */
class ListTimeRulesRequest extends SetupListRequest
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
