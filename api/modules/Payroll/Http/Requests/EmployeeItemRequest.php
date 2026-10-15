<?php

namespace Modules\Payroll\Http\Requests;

use Modules\Payroll\Models\EmployeeItem;
use Modules\Payroll\Models\PayrollEmployee;

/**
 * PATCH payroll/employee-items/{payroll_employee_item}, POST …/archive
 * (PAY-06): `payroll.employee.manage` at the employee's place; amounts
 * not read-only for the reader (PAY-23). A one-off already paid is not
 * changed.
 */
class EmployeeItemRequest extends PayrollRequest
{
    public function entry(): EmployeeItem
    {
        return $this->route('payroll_employee_item');
    }

    public function authorize(): bool
    {
        $employee = PayrollEmployee::query()->findOrFail($this->entry()->payroll_employee_id);

        return $this->onRecord($employee, 'payroll.employee.manage', 'payroll.employee.view');
    }

    public function rules(): array
    {
        return $this->isMethod('PATCH') ? [
            'amount_minor' => ['sometimes', 'string', 'regex:/^[1-9]\d{0,17}$/'],
            'from_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'period_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ] : [];
    }

    public function after(): array
    {
        return [function () {
            $this->payFields()->assertEditable($this->user());
        }];
    }
}
