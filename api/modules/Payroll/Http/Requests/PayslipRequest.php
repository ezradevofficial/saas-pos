<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Payslips\Payslips;
use Modules\Payroll\Support\Errors;
use Modules\Payroll\Support\PayFields;

/**
 * Payslips (PAY-19, PAY-24). `payroll/payslips…`: holders of
 * `payroll.payslip.view` at the employee's place whose field rules do not
 * hide amounts. `payroll/me/payslips…`: the employee themself, no
 * permission needed. Anyone else: not found.
 */
class PayslipRequest extends PayrollRequest
{
    public function payslip(): ?Payslip
    {
        $payslip = $this->route('payroll_payslip');

        return $payslip instanceof Payslip ? $payslip : null;
    }

    public function own(): bool
    {
        return str_starts_with((string) $this->route()?->getName(), 'payroll.me.');
    }

    public function authorize(): bool
    {
        $payslips = app(Payslips::class);
        $payslip = $this->payslip();

        if ($this->own()) {
            if ($payslips->ownEmployeeIds($this->user()) === []) {
                throw Errors::make(403, 'not_an_employee');
            }

            abort_unless($payslip === null || $payslips->isOwn($this->user(), $payslip), 404);

            return true;
        }

        if ($payslip !== null) {
            abort_unless($payslips->readsAsPayroll($this->user(), $payslip), 404);

            return true;
        }

        return $this->access()->anywhere($this->user(), Payslips::VIEW) && ! $this->payFields()->hides(PayFields::AMOUNTS);
    }

    public function rules(): array
    {
        return $this->payslip() !== null ? [] : [
            'run' => ['sometimes', 'uuid', Rule::exists('payroll_runs', 'id')],
            'employee' => ['sometimes', 'uuid', Rule::exists('payroll_employees', 'id')],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
