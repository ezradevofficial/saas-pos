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
        return ['as_of' => ['sometimes', 'date_format:Y-m-d']];
    }
}
