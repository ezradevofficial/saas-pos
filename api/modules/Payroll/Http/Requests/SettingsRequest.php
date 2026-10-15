<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Tenancy\Models\Company;

/**
 * GET/PUT payroll/settings/{company}, GET payroll/statutory/{company}
 * (PAY-02, PAY-08): `payroll.settings.view` / `manage` at the company.
 */
class SettingsRequest extends PayrollRequest
{
    public function company(): Company
    {
        return $this->route('company');
    }

    public function authorize(): bool
    {
        return $this->onCompany($this->company()->id, 'payroll.settings.manage', 'payroll.settings.view') || $this->isMethod('GET');
    }

    public function rules(): array
    {
        if (! $this->isMethod('PUT')) {
            return [];
        }

        $multiplier = ['sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/'];

        return [
            'overtime_multiplier' => $multiplier,
            'night_multiplier' => $multiplier,
            'holiday_multiplier' => $multiplier,
            'rest_day_multiplier' => $multiplier,
            'monthly_hours' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'monthly_days' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,2}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'premiums_taxable' => ['sometimes', 'boolean'],
            'premiums_contributory' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            // A premium multiplier pays (multiplier − 1) on top: below 1 would take pay away.
            foreach (['night_multiplier', 'holiday_multiplier', 'rest_day_multiplier'] as $field) {
                $value = $this->input($field);

                if (is_string($value) && is_numeric($value) && (float) $value < 1) {
                    $validator->errors()->add($field, __('payroll.errors.multiplier_below_one'));
                }
            }
        }];
    }
}
