<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Http\Lists\LoanList;
use Modules\Payroll\Http\Requests\Concerns\ListsPayroll;
use Modules\Payroll\Models\Loan;

/** GET payroll/loans (PAY-13, PAY-25): loans of the employees at the reader's places, `?employee=`. */
class ListLoansRequest extends FormRequest
{
    use ListsPayroll;

    public function list(): ListDefinition
    {
        return new LoanList;
    }

    protected function viewPermission(): string
    {
        return 'payroll.loan.view';
    }

    protected function statuses(): array
    {
        return [Loan::ACTIVE, Loan::SETTLED, Loan::CANCELLED];
    }

    protected function extraRules(): array
    {
        return ['employee' => ['sometimes', 'uuid', Rule::exists('payroll_employees', 'id')]];
    }
}
