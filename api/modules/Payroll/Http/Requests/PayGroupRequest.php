<?php

namespace Modules\Payroll\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Payroll\Models\PayGroup;

/**
 * PATCH payroll/pay-groups/{payroll_pay_group}, POST …/archive (PAY-03):
 * not found unless the reader reads the company's payroll setup;
 * `payroll.settings.manage` at the company to change it.
 */
class PayGroupRequest extends PayrollRequest
{
    public static function rulesFor(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'name' => [$required, 'string', 'max:120'],
            'frequency' => [$creating ? 'required' : 'prohibited', 'string', Rule::in(PayGroup::FREQUENCIES)],
            'anchor_date' => [Rule::requiredIf(fn () => $creating && in_array(request()->input('frequency'), [PayGroup::WEEKLY, PayGroup::BIWEEKLY], true)), 'nullable', 'date_format:Y-m-d'],
            'pay_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
        ];
    }

    public function group(): PayGroup
    {
        return $this->route('payroll_pay_group');
    }

    public function authorize(): bool
    {
        return $this->onCompany($this->group()->company_id, StoreSetupRequest::MANAGE, SetupListRequest::VIEW);
    }

    public function rules(): array
    {
        return $this->isMethod('PATCH') ? self::rulesFor(false) : [];
    }
}
