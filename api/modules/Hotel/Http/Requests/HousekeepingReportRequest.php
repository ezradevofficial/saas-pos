<?php

namespace Modules\Hotel\Http\Requests;

/** GET hotel/reports/housekeeping (HOT-31): `hotel.report.view` at the reader's branches; the task list with its filters (tasks done, by whom, when). */
class HousekeepingReportRequest extends ListTasksRequest
{
    protected function permission(): string
    {
        return 'hotel.report.view';
    }
}
