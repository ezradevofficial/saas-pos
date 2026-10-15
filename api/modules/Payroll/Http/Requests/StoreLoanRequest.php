<?php

namespace Modules\Payroll\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Models\PayrollEmployee;

/** POST payroll/loans (PAY-13): `payroll.loan.manage` at the employee's place; amounts not read-only (PAY-23). */
class StoreLoanRequest extends PayrollRequest
{
    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), 'payroll.loan.manage');
    }

    public function rules(): array
    {
        return [
            'payroll_employee_id' => ['required', 'uuid', Rule::exists('payroll_employees', 'id')],
            'kind' => ['required', 'string', Rule::in(Loan::KINDS)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:80'],
            'principal_minor' => ['required', 'string', 'regex:/^[1-9]\d{0,17}$/'],
            'instalment_minor' => ['required', 'string', 'regex:/^[1-9]\d{0,17}$/'],
            'currency' => ['required', 'string', 'size:3', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            'disbursed_on' => ['required', 'date_format:Y-m-d'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:disbursed_on'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function employee(): PayrollEmployee
    {
        return PayrollEmployee::query()->findOrFail($this->validated('payroll_employee_id'));
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $employee = PayrollEmployee::query()->find($this->input('payroll_employee_id'));

            if ($employee === null || ! $this->access()->can($this->user(), 'payroll.loan.manage', $employee)) {
                $this->forbid();
            }

            $this->payFields()->assertEditable($this->user());
        }];
    }
}
