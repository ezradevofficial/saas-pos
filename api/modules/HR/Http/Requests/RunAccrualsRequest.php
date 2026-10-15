<?php

namespace Modules\HR\Http\Requests;

/** POST hr/leave-accruals/run `{company_id, as_of?}` (HR-13): `hr.leave.manage` at the company. */
class RunAccrualsRequest extends CompanyActionRequest
{
    protected function permission(): string
    {
        return 'hr.leave.manage';
    }

    protected function fields(): array
    {
        // HR-13: never ahead of today (a future run would grant leave not yet earned).
        return ['as_of' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today']];
    }
}
