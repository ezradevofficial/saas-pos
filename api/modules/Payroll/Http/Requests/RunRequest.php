<?php

namespace Modules\Payroll\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Payroll\Models\Run;

/**
 * One run (PAY-14 to PAY-20), at its company: reading needs
 * `payroll.run.view`; calculating, submitting, cancelling and corrections
 * `payroll.run.prepare`; posting `payroll.run.post`; payment files
 * `payroll.payment.export`. Out of reach is not found.
 */
class RunRequest extends PayrollRequest
{
    /** Route name => the permission it needs. */
    public const PERMISSIONS = [
        'payroll.runs.show' => 'payroll.run.view',
        'payroll.runs.calculate' => 'payroll.run.prepare',
        'payroll.runs.submit' => 'payroll.run.prepare',
        'payroll.runs.cancel' => 'payroll.run.prepare',
        'payroll.runs.corrections' => 'payroll.run.prepare',
        'payroll.runs.post' => 'payroll.run.post',
        'payroll.runs.payment-file' => 'payroll.payment.export',
        'payroll.runs.payment-summary' => 'payroll.payment.export',
    ];

    public function run(): Run
    {
        return $this->route('payroll_run');
    }

    public function authorize(): bool
    {
        $permission = self::PERMISSIONS[$this->route()?->getName()] ?? 'payroll.run.view';

        return $this->onCompany($this->run()->company_id, $permission, 'payroll.run.view');
    }

    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'payroll.runs.cancel' => ['reason' => ['sometimes', 'nullable', 'string', 'max:500']],
            'payroll.runs.corrections' => [
                'mode' => ['required', 'string', Rule::in([Run::REVERSE, Run::RECALCULATE])],
                'employee_ids' => ['sometimes', 'array', 'min:1'],
                'employee_ids.*' => ['uuid', 'distinct', Rule::exists('payroll_employees', 'id')],
                'pay_date' => ['sometimes', 'date_format:Y-m-d'],
                'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'payroll.runs.payment-file', 'payroll.runs.payment-summary' => ['format' => ['required', 'string', 'max:40']],
            default => [],
        };
    }
}
