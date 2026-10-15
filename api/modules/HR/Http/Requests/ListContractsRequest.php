<?php

namespace Modules\HR\Http\Requests;

/** GET hr/employees/{hr_employee}/contracts (HR-07): `hr.contract.view` at the place. */
class ListContractsRequest extends EmployeeGroupRequest
{
    protected function permission(): string
    {
        return 'hr.contract.view';
    }
}
