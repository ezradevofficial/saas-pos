<?php

namespace Modules\HR\Http\Requests;

/**
 * Reads and actions on one employee that need one permission at the place
 * (subclasses name it): GET pay accounts (`hr.bank.view`), contracts
 * (`hr.contract.view`), the clock-in PIN clear (`hr.attendance.manage`).
 */
abstract class EmployeeGroupRequest extends HrRequest
{
    abstract protected function permission(): string;

    public function authorize(): bool
    {
        return $this->onEmployee($this->employee(), $this->permission());
    }
}
