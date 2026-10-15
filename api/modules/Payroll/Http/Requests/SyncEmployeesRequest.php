<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST payroll/employees/sync (PAY-05): refresh a company's copy from HR; `payroll.employee.manage` at the company. */
class SyncEmployeesRequest extends PayrollRequest
{
    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), 'payroll.employee.manage');
    }

    public function rules(): array
    {
        return ['company_id' => ['required', 'uuid', Rule::exists('companies', 'id')]];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && ! $this->access()->at($this->user(), 'payroll.employee.manage', Scope::company((string) $this->input('company_id')))) {
                $this->forbid();
            }
        }];
    }
}
