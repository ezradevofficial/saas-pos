<?php

namespace Modules\HR\Http\Requests;

/** POST hr/leave-types/apply-pack `{company_id}` (HR-12): `hr.leave.manage` at the company. */
class ApplyPackRequest extends CompanyActionRequest
{
    protected function permission(): string
    {
        return 'hr.leave.manage';
    }

    protected function fields(): array
    {
        return [];
    }
}
