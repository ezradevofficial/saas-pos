<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Payroll\Models\PayGroup;

/** POST payroll/runs (PAY-14): `payroll.run.prepare` at the pay group's company. */
class StoreRunRequest extends PayrollRequest
{
    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), 'payroll.run.prepare');
    }

    public function rules(): array
    {
        return [
            'pay_group_id' => ['required', 'uuid', Rule::exists('payroll_pay_groups', 'id')],
            'period_date' => ['sometimes', 'date_format:Y-m-d'],
            'pay_date' => ['sometimes', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $company = PayGroup::query()->whereKey($this->input('pay_group_id'))->value('company_id');

            if (! $this->access()->at($this->user(), 'payroll.run.prepare', Scope::company((string) $company))) {
                $this->forbid();
            }
        }];
    }
}
