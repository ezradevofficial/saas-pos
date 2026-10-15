<?php

namespace Modules\Payroll\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Payroll\Models\Loan;

/**
 * GET/PATCH payroll/loans/{payroll_loan}, POST …/settle, …/cancel (PAY-13):
 * `payroll.loan.view` / `manage` at the employee's place (not found out of
 * it); changes need amounts not read-only (PAY-23).
 */
class LoanRequest extends PayrollRequest
{
    public function loan(): Loan
    {
        return $this->route('payroll_loan');
    }

    public function authorize(): bool
    {
        $manage = $this->onRecord($this->loan(), 'payroll.loan.manage', 'payroll.loan.view');

        return $this->isMethod('GET') || $manage;
    }

    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return [
                'instalment_minor' => ['sometimes', 'string', 'regex:/^[1-9]\d{0,17}$/'],
                'starts_on' => ['sometimes', 'date_format:Y-m-d'],
                'reference' => ['sometimes', 'nullable', 'string', 'max:80'],
                'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            ];
        }

        if ($this->routeIs('payroll.loans.settle')) {
            return [
                'method' => ['required', 'string', Rule::in(['external', 'next_run'])],
                'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            ];
        }

        return $this->routeIs('payroll.loans.cancel') ? ['reason' => ['sometimes', 'nullable', 'string', 'max:500']] : [];
    }

    public function after(): array
    {
        return [function () {
            if (! $this->isMethod('GET')) {
                $this->payFields()->assertEditable($this->user());
            }
        }];
    }
}
