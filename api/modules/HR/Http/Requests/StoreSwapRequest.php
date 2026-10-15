<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Validation\Rule;

/** POST hr/shift-swaps (HR-19): `hr.roster.manage` at the requesting employee's place (asking for them). */
class StoreSwapRequest extends EmployeeBodyRequest
{
    protected function permission(): string
    {
        return 'hr.roster.manage';
    }

    public function rules(): array
    {
        return ['employee_id' => ['required', 'uuid', Rule::exists('hr_employees', 'id')], ...SwapRules::rules()];
    }
}
