<?php

namespace Modules\Payroll\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Payroll\Models\Item;
use Modules\Payroll\Models\PayGroup;
use Modules\Payroll\Models\PayrollEmployee;

/**
 * GET/PATCH payroll/employees/{payroll_employee}, GET/POST …/items (PAY-05,
 * PAY-06): `payroll.employee.view` / `manage` at the employee's place
 * (not found out of it). Changing pay (currency, items) is refused when
 * the reader's field rules make amounts read-only (PAY-23).
 */
class PayEmployeeRequest extends PayrollRequest
{
    public function employee(): PayrollEmployee
    {
        return $this->route('payroll_employee');
    }

    public function authorize(): bool
    {
        $manage = $this->onRecord($this->employee(), 'payroll.employee.manage', 'payroll.employee.view');

        return $this->isMethod('GET') || $manage;
    }

    public function rules(): array
    {
        if ($this->isMethod('PATCH')) {
            return [
                'pay_group_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('payroll_pay_groups', 'id')->whereNull('archived_at')],
                'pay_currency' => ['sometimes', 'nullable', 'string', 'size:3', Rule::exists('tenant_currencies', 'code')->where('active', true)],
                'payment_method' => ['sometimes', 'nullable', 'string', Rule::in(PayrollEmployee::METHODS)],
                'dependants' => ['sometimes', 'integer', 'between:0,50'],
                'children' => ['sometimes', 'integer', 'between:0,50'],
                'on_hold' => ['sometimes', 'boolean'],
            ];
        }

        if ($this->isMethod('POST')) {
            return [
                'item_id' => ['required', 'uuid', Rule::exists('payroll_items', 'id')->whereNull('archived_at')],
                'amount_minor' => ['required', 'string', 'regex:/^[1-9]\d{0,17}$/'],
                'currency' => ['required', 'string', 'size:3', Rule::exists('tenant_currencies', 'code')->where('active', true)],
                'recurrence' => ['required', 'string', Rule::in(['recurring', 'once'])],
                'from_date' => ['required_if:recurrence,recurring', 'nullable', 'date_format:Y-m-d'],
                'to_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
                'period_date' => ['required_if:recurrence,once', 'nullable', 'date_format:Y-m-d'],
                'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            ];
        }

        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->isMethod('GET')) {
                return;
            }

            $employee = $this->employee();

            if ($this->filled('pay_group_id') && PayGroup::query()->whereKey($this->input('pay_group_id'))->value('company_id') !== $employee->company_id) {
                $validator->errors()->add('pay_group_id', __('payroll.errors.pay_group_other_company'));
            }

            if ($this->filled('item_id') && Item::query()->whereKey($this->input('item_id'))->value('company_id') !== $employee->company_id) {
                $validator->errors()->add('item_id', __('payroll.errors.item_other_company'));
            }

            // PAY-06: an item is in the employee's pay currency.
            if ($this->isMethod('POST') && $employee->pay_currency !== null && $this->input('currency') !== $employee->pay_currency) {
                $validator->errors()->add('currency', __('payroll.errors.currency_mismatch'));
            }

            if ($this->isMethod('POST') || $this->has('pay_currency')) {
                $this->payFields()->assertEditable($this->user());
            }
        }];
    }
}
