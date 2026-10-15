<?php

namespace Modules\HR\Http\Requests;

/** GET hr/appraisal-cycles (HR-30): rows of the companies the reader reads with `hr.appraisal.view`. */
class ListAppraisalCyclesRequest extends SetupListRequest
{
    protected function viewPermission(): string
    {
        return 'hr.appraisal.view';
    }

    protected function archivable(): bool
    {
        return false;
    }
}
