<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Models\Contract;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Sensitive;

/**
 * PATCH hr/contracts/{hr_contract} and POST …/end (HR-07):
 * `hr.contract.manage` at the employee's place (pay changes need
 * `hr.pay.edit`); a contract out of the reader's places is not found.
 */
class ContractRequest extends HrRequest
{
    public function contract(): Contract
    {
        return $this->route('hr_contract');
    }

    public function employee(): Employee
    {
        return Employee::query()->findOrFail($this->contract()->employee_id);
    }

    public function authorize(): bool
    {
        if (! $this->onEmployee($this->employee(), 'hr.contract.manage', 'hr.contract.view')) {
            return false;
        }

        if ($this->isMethod('PATCH') && array_intersect(array_keys($this->all()), ContractRules::PAY_INPUTS) !== []) {
            return $this->sensitive()->mayEdit($this->user(), $this->employee(), Sensitive::PAY);
        }

        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return ContractRules::rules(false);
        }

        return ['end_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['sometimes', 'nullable', 'string', 'max:500']];
    }
}
