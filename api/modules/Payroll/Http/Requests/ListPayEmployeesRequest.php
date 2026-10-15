<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Http\Lists\PayEmployeeList;
use Modules\Payroll\Http\Requests\Concerns\ListsPayroll;

/** GET payroll/employees (PAY-05, PAY-25): employees at the reader's places, `?pay_group=`, `?unassigned=1`. */
class ListPayEmployeesRequest extends FormRequest
{
    use ListsPayroll;

    public function list(): ListDefinition
    {
        return new PayEmployeeList;
    }

    protected function viewPermission(): string
    {
        return 'payroll.employee.view';
    }

    protected function statuses(): array
    {
        return ['active', 'terminated'];
    }

    protected function extraRules(): array
    {
        return [
            'pay_group' => ['sometimes', 'uuid', Rule::exists('payroll_pay_groups', 'id')],
            'unassigned' => ['sometimes', 'boolean'],
        ];
    }
}
