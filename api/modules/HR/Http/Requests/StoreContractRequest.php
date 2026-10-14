<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Support\Sensitive;

/** POST hr/employees/{hr_employee}/contracts (HR-07): `hr.contract.manage` and `hr.pay.edit` (pay is part of it) at the place. */
class StoreContractRequest extends HrRequest
{
    public function authorize(): bool
    {
        return $this->onEmployee($this->employee(), 'hr.contract.manage') && $this->sensitive()->mayEdit($this->user(), $this->employee(), Sensitive::PAY);
    }

    public function rules(): array
    {
        return ContractRules::rules(true);
    }
}
