<?php

namespace Modules\HR\Http\Requests;

/** POST hr/appraisal-cycles (HR-30): `hr.appraisal.manage` at the company. */
class StoreAppraisalCycleRequest extends SetupStoreRequest
{
    protected function permission(): string
    {
        return 'hr.appraisal.manage';
    }

    protected function fields(): array
    {
        return [...AppraisalCycleRequest::FIELDS(true), 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start']];
    }
}
