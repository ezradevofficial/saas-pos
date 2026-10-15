<?php

namespace Modules\HR\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use App\Core\Tenancy\Models\Location;
use Illuminate\Validation\Validator;
use Modules\HR\Employees\EmployeeEntity;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Sensitive;

/**
 * PATCH hr/employees/{hr_employee} (HR-02): `hr.employee.edit` at the
 * employee's place (and `hr.employee.create` at a new place it moves to);
 * date of birth and medical notes need their group's edit permission
 * (HR-09); hidden or locked fields are refused (RBAC-05).
 */
class UpdateEmployeeRequest extends HrRequest
{
    use GuardsFieldRules {
        GuardsFieldRules::withValidator as guardFieldRules;
    }
    use LinksUser;

    protected string $fieldRulesResource = Sensitive::RESOURCE;

    public function authorize(): bool
    {
        return $this->onEmployee($this->employee(), 'hr.employee.edit');
    }

    public function rules(): array
    {
        return EmployeeRules::rules(false);
    }

    public function withValidator(Validator $validator): void
    {
        $this->guardFieldRules($validator);

        $validator->after(function (Validator $validator) {
            $employee = $this->employee();
            app(CustomFieldValidator::class)->validate($validator, EmployeeEntity::KEY, $this->input('custom'), $employee, $this->user());

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (EmployeeRules::SENSITIVE_INPUTS as $key => $group) {
                if ($this->has($key) && ! $this->sensitive()->mayEdit($this->user(), $employee, $group)) {
                    $this->forbid();
                }
            }

            $this->guardLink($employee->scope(), $employee->user_id);

            if ($this->has('location_id') || $this->has('branch_id')) {
                $location = $this->has('location_id') ? $this->input('location_id') : $employee->location_id;
                $branch = $location !== null ? Location::query()->whereKey($location)->value('branch_id') : ($this->has('branch_id') ? $this->input('branch_id') : $employee->branch_id);

                if (! $this->access()->at($this->user(), 'hr.employee.create', Employee::placeScope($employee->company_id, $branch, $location))) {
                    $this->forbid();
                }
            }
        });
    }
}
