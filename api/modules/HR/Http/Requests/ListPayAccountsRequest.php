<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Support\Sensitive;

/** GET hr/employees/{hr_employee}/pay-accounts (HR-04): `hr.bank.view` at the place, not hidden by field rules. */
class ListPayAccountsRequest extends EmployeeGroupRequest
{
    protected function permission(): string
    {
        return 'hr.bank.view';
    }

    public function authorize(): bool
    {
        return parent::authorize() && $this->sensitive()->sees($this->user(), $this->employee(), Sensitive::BANK, $this);
    }

    public function rules(): array
    {
        return ['status' => ['sometimes', 'string', 'in:active,archived,all']];
    }
}
