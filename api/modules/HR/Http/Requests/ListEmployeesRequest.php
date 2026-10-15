<?php

namespace Modules\HR\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Http\Lists\EmployeeList;
use Modules\HR\Http\Requests\Concerns\ListsHr;
use Modules\HR\Models\Employee;

/** GET hr/employees (HR-35): employees at the reader's places; `?status=active|terminated|all` (all by default). */
class ListEmployeesRequest extends FormRequest
{
    use ListsHr;

    public function list(): ListDefinition
    {
        return new EmployeeList;
    }

    protected function viewPermission(): string
    {
        return 'hr.employee.view';
    }

    protected function statuses(): array
    {
        return Employee::STATUSES;
    }
}
