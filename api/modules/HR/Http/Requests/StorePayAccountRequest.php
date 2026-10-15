<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Support\Sensitive;

/** POST hr/employees/{hr_employee}/pay-accounts (HR-04): `hr.bank.edit` at the place, `pay_accounts` not locked. */
class StorePayAccountRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->onEmployee($this->employee(), 'hr.bank.edit') && $this->sensitive()->mayEdit($this->user(), $this->employee(), Sensitive::BANK);
    }

    public function rules(): array
    {
        return PayAccountRules::rules(true);
    }
}
