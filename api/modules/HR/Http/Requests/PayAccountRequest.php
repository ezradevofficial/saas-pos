<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Models\Employee;
use Modules\HR\Models\PayAccount;
use Modules\HR\Support\Sensitive;

/**
 * PATCH hr/pay-accounts/{hr_pay_account} and POST …/archive (HR-04):
 * `hr.bank.edit` at the employee's place, `pay_accounts` not locked; an
 * account the reader does not see is not found.
 */
class PayAccountRequest extends HrRequest
{
    public function account(): PayAccount
    {
        return $this->route('hr_pay_account');
    }

    public function employee(): Employee
    {
        return Employee::query()->findOrFail($this->account()->employee_id);
    }

    public function authorize(): bool
    {
        abort_unless($this->sensitive()->sees($this->user(), $this->employee(), Sensitive::BANK, $this), 404);

        return $this->sensitive()->mayEdit($this->user(), $this->employee(), Sensitive::BANK);
    }

    public function rules(): array
    {
        return $this->isMethod('PATCH') ? PayAccountRules::rules(false) : [];
    }
}
