<?php

namespace Modules\HR\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use App\Core\Tenancy\Models\Location;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\HR\Employees\EmployeeEntity;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Sensitive;

/**
 * POST hr/employees (HR-02, HR-05): `hr.employee.create` at the new
 * employee's place; date of birth and medical notes need their group's
 * edit permission there (HR-09); fields the reader's rules hide or lock
 * are refused (RBAC-05).
 */
class StoreEmployeeRequest extends HrRequest
{
    use GuardsFieldRules {
        GuardsFieldRules::withValidator as guardFieldRules;
    }

    protected string $fieldRulesResource = Sensitive::RESOURCE;

    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), 'hr.employee.create');
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            ...EmployeeRules::rules(true),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->guardFieldRules($validator);

        $validator->after(function (Validator $validator) {
            app(CustomFieldValidator::class)->validate($validator, EmployeeEntity::KEY, $this->input('custom'), null, $this->user());

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $location = $this->input('location_id');
            $branch = $location !== null ? Location::query()->whereKey($location)->value('branch_id') : $this->input('branch_id');
            $scope = Employee::placeScope((string) $this->input('company_id'), $branch, $location);

            if (! $this->access()->at($this->user(), 'hr.employee.create', $scope)) {
                $this->forbid();
            }

            foreach (EmployeeRules::SENSITIVE_INPUTS as $key => $group) {
                if ($this->filled($key) && ! $this->access()->at($this->user(), Sensitive::GROUPS[$group][1], $scope)) {
                    $this->forbid();
                }
            }

            $this->guardLink($scope, null);
        });
    }
}
